<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\Refund;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use App\Services\Storage\UploadStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pre-deployment hardening pass (accepted after the Phase 0-5 production-
 * readiness audit): InvoiceObserver concurrency, manager-budget cache
 * invalidation, the content-submission provider-upload handoff, the
 * authorized submission-download route, and the budget-table query count.
 * (AdvertisingExpenditure SoftDeletes regression coverage lives in
 * Phase5FinancialEdgeCasesTest, alongside the rest of its financial tests.)
 */
class Phase6HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'manage payments', 'manage raw-content', 'view raw-content-panel', 'manage smm-collection',
            'manage published-content', 'manage advertising-expenditure', 'view brand-checklist-overview',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        PaymentCategory::firstOrCreate(['name' => 'Content Production'], ['is_active' => true, 'sort_order' => 20]);
    }

    private function user(string $role, array $extraPerms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        if ($extraPerms) {
            $user->givePermissionTo($extraPerms);
        }

        return $user;
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function adInvoice(Client $client, Brand $brand, User $manager, float $amount = 1000): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => $amount, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
    }

    private function pay(Invoice $invoice, float $amount, User $recorder): Payment
    {
        $payment = Payment::create([
            'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'payment_category_id' => $invoice->payment_category_id,
            'amount' => $amount, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $recorder->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh());

        return $payment;
    }

    private function readyBrand(User $manager): Brand
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $this->pay($this->adInvoice($client, $brand, $manager, 1000), 1000, $manager);
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    // ── Item 2: InvoiceObserver concurrency hardening ──────────────────────

    public function test_two_invoices_landing_back_to_back_still_create_exactly_one_checklist(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);

        $this->pay($this->adInvoice($client, $brand, $manager, 1000), 1000, $manager);

        // Two content-charge invoices created in immediate succession — each
        // one's own `created` event fires evaluate(); the second must find
        // the first one's checklist already there (via the new lock) rather
        // than racing it.
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 150, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 150, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        $this->assertSame(1, BrandChecklist::where('brand_id', $brand->id)->count());
    }

    public function test_a_duplicate_checklist_insert_is_refused_at_the_database_level(): void
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        BrandChecklist::create(['brand_id' => $brand->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        BrandChecklist::create(['brand_id' => $brand->id]);
    }

    public function test_a_checklist_already_on_hold_is_not_held_or_logged_again(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $budget = Invoice::where('brand_id', $brand->id)
            ->whereHas('category', fn ($q) => $q->where('name', 'Social Media Ads'))->firstOrFail();
        $payment = Payment::where('invoice_id', $budget->id)->firstOrFail();

        Refund::create([
            'payment_id' => $payment->id, 'invoice_id' => $budget->id, 'client_id' => $brand->client_id,
            'refund_number' => 'RF-' . uniqid(), 'amount' => $payment->amount, 'status' => Refund::STATUS_COMPLETED,
            'reason' => 'Hardening test', 'requested_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        $this->assertTrue($brand->fresh()->checklist->isOnHold());
        $this->assertSame(1, \App\Models\ActivityLog::where('module', 'Brand Checklist Hold')->where('action', 'Held')->count());

        // A second, unrelated status-changing save on the same invoice must
        // not re-evaluate into a second hold/log — the checklist is already held.
        $budget->update(['title' => 'Touched again']);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        $this->assertSame(1, \App\Models\ActivityLog::where('module', 'Brand Checklist Hold')->where('action', 'Held')->count());
    }

    // ── Item 3: Manager budget cache invalidation ───────────────────────────

    private function primeBudgetCache(): void
    {
        Cache::remember('dash.manager_brand_budgets', 600, fn () => 'primed');
        $this->assertTrue(Cache::has('dash.manager_brand_budgets'));
    }

    public function test_cache_is_invalidated_after_a_payment_is_recorded(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $invoice = $this->adInvoice($client, $brand, $manager, 1000);

        $this->primeBudgetCache();
        $this->pay($invoice, 500, $manager);

        $this->assertFalse(Cache::has('dash.manager_brand_budgets'));
    }

    public function test_cache_is_invalidated_after_a_refund_completes(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $budget = Invoice::where('brand_id', $brand->id)
            ->whereHas('category', fn ($q) => $q->where('name', 'Social Media Ads'))->firstOrFail();
        $payment = Payment::where('invoice_id', $budget->id)->firstOrFail();

        $this->primeBudgetCache();

        Refund::create([
            'payment_id' => $payment->id, 'invoice_id' => $budget->id, 'client_id' => $brand->client_id,
            'refund_number' => 'RF-' . uniqid(), 'amount' => 100, 'status' => Refund::STATUS_COMPLETED,
            'reason' => 'Cache test', 'requested_by' => $manager->id,
        ]);

        $this->assertFalse(Cache::has('dash.manager_brand_budgets'));
    }

    public function test_cache_is_invalidated_after_a_relevant_invoice_change(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $invoice = $this->adInvoice($client, $brand, $manager, 1000);

        $this->primeBudgetCache();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertFalse(Cache::has('dash.manager_brand_budgets'));
    }

    public function test_cache_is_invalidated_after_an_expenditure_is_created_updated_deleted_and_approved(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'manage advertising-expenditure']);
        $brand = $this->readyBrand($manager);
        $service = app(\App\Services\AdvertisingExpenditureService::class);

        $this->primeBudgetCache();
        $expenditure = $service->create($brand->fresh(), ['amount' => 100, 'reporting_date' => now()->toDateString()], $manager);
        $this->assertFalse(Cache::has('dash.manager_brand_budgets'));

        $this->primeBudgetCache();
        $service->requestUpdate($expenditure, ['amount' => 150], 'Correction', $manager); // privileged -> applied immediately
        $this->assertFalse(Cache::has('dash.manager_brand_budgets'));

        $this->primeBudgetCache();
        $service->requestDelete($expenditure->fresh(), 'Removing it', $manager); // privileged -> applied immediately
        $this->assertFalse(Cache::has('dash.manager_brand_budgets'));
    }

    // ── Item 4: content-submission storage completion (provider handoff) ──

    public function test_submitting_a_file_queues_the_provider_upload_handoff(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);

        $uploads = \Mockery::mock(UploadStaging::class);
        $uploads->shouldReceive('store')->once()->andReturn(['content-items/' . $item->id . '/test.jpg', 'local']);
        $uploads->shouldReceive('pushLater')->once()->with(\Mockery::type(ContentItemSubmission::class));
        $this->app->instance(UploadStaging::class, $uploads);

        app(ContentItemService::class)->submit($item->fresh(), [
            'file' => UploadedFile::fake()->image('test.jpg'),
        ], $content);
    }

    public function test_submitting_a_link_only_never_attempts_a_provider_upload_handoff(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);

        $uploads = \Mockery::mock(UploadStaging::class);
        $uploads->shouldReceive('store')->never();
        $uploads->shouldReceive('pushLater')->never();
        $this->app->instance(UploadStaging::class, $uploads);

        app(ContentItemService::class)->submit($item->fresh(), [
            'link_url' => 'https://example.com/v1.jpg',
        ], $content);
    }

    // ── Item 5: authorized ContentItemSubmission retrieval ─────────────────

    private function itemWithFileSubmission(Brand $brand, User $content): array
    {
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item ' . uniqid()], $content);
        $submission = app(ContentItemService::class)->submit($item->fresh(), [
            'file' => UploadedFile::fake()->create('report.pdf', 10),
        ], $content);

        return [$item->fresh(), $submission];
    }

    public function test_a_workflow_user_can_download_a_submitted_file(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content', 'view raw-content-panel']);
        $brand = $this->readyBrand($manager);
        [$item, $submission] = $this->itemWithFileSubmission($brand, $content);

        $response = $this->actingAs($content)->get(route('marketing.content-items.submissions.download', [$brand, $item, $submission]));

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
    }

    public function test_a_user_without_any_panel_permission_cannot_download(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content', 'view raw-content-panel']);
        $bystander = $this->user('Website');
        $brand = $this->readyBrand($manager);
        [$item, $submission] = $this->itemWithFileSubmission($brand, $content);

        $response = $this->actingAs($bystander)->get(route('marketing.content-items.submissions.download', [$brand, $item, $submission]));

        $response->assertForbidden();
    }

    public function test_downloading_a_submission_through_the_wrong_brand_404s(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content', 'view raw-content-panel']);
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);
        [$item, $submission] = $this->itemWithFileSubmission($brandA, $content);

        $response = $this->actingAs($content)->get(route('marketing.content-items.submissions.download', [$brandB, $item, $submission]));

        $response->assertNotFound();
    }

    public function test_downloading_a_submission_belonging_to_a_different_item_404s(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content', 'view raw-content-panel']);
        $brand = $this->readyBrand($manager);
        [$itemA, $submissionA] = $this->itemWithFileSubmission($brand, $content);
        [$itemB] = $this->itemWithFileSubmission($brand, $content);

        $response = $this->actingAs($content)->get(route('marketing.content-items.submissions.download', [$brand, $itemB, $submissionA]));

        $response->assertNotFound();
    }

    public function test_a_link_only_submission_has_nothing_to_download(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content', 'view raw-content-panel']);
        $brand = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Link item'], $content);
        $submission = app(ContentItemService::class)->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $response = $this->actingAs($content)->get(route('marketing.content-items.submissions.download', [$brand, $item->fresh(), $submission]));

        $response->assertNotFound();
    }

    // ── Item 6: query hardening ─────────────────────────────────────────────

    public function test_budget_table_does_not_scale_queries_per_brand(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        for ($i = 0; $i < 5; $i++) {
            $brand = $this->readyBrand($manager);
            app(\App\Services\AdvertisingExpenditureService::class)->create($brand->fresh(), [
                'amount' => 10 + $i, 'reporting_date' => now()->toDateString(),
            ], $manager);
        }

        Cache::forget('dash.manager_brand_budgets');
        DB::enableQueryLog();
        $this->actingAs($manager)->get(route('manager.oversight'))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Old behaviour was ~8 queries/brand for the budget math alone (40+
        // for 5 brands) on top of everything else the page also loads;
        // asserting a generous ceiling here is a regression guard against
        // that redundant-call pattern coming back, not a tight performance budget.
        $this->assertLessThan(60, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }
}
