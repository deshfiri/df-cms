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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 1 of the Brand Content & Advertising DMS integration: the checklist
 * engine — auto-creation, the on-hold trigger (Fix E), and content-item
 * create/submit/request-revision (Fix C). See
 * C:\Users\DST\.claude\plans\soft-nibbling-quilt.md.
 */
class BrandContentAdvertisingPhase1Test extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view clients', 'manage clients', 'manage payments', 'manage products',
            'view raw-content-panel', 'manage raw-content', 'view designer-panel', 'manage designer-content',
            'view smm-panel', 'manage smm-collection', 'manage publishing-review', 'view brand-checklist-overview'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        PaymentCategory::firstOrCreate(['name' => 'Content Production'], ['is_active' => true, 'sort_order' => 20]);
    }

    private function client(string $name = 'Test Client'): Client
    {
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => $name, 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
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

    private function invoice(Client $client, Brand $brand, string $categoryName, User $issuer): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', $categoryName)->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 1000, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $issuer->id, 'issued_date' => now(),
        ]);
    }

    private function pay(Invoice $invoice, float $amount, User $recorder): Payment
    {
        $payment = Payment::create([
            'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id,
            'payment_category_id' => $invoice->payment_category_id, 'amount' => $amount,
            'status' => 'Paid', 'payment_date' => now(), 'created_by' => $recorder->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice);

        return $payment;
    }

    // ── Auto-creation trigger ────────────────────────────────────────────

    public function test_checklist_is_created_once_budget_is_paid_and_content_charge_exists(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $budget = $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $this->assertDatabaseMissing('brand_checklists', ['brand_id' => $brand->id]);

        $this->pay($budget, 1000, $manager);
        // Only the budget is paid so far — the content charge doesn't exist yet.
        $this->assertDatabaseMissing('brand_checklists', ['brand_id' => $brand->id]);

        // Content charge created WITHOUT any payment — this is the exact case
        // Fix 1 exists for: the checklist must not require it to be paid.
        $this->invoice($client, $brand, 'Content Production', $manager);

        $this->assertDatabaseHas('brand_checklists', ['brand_id' => $brand->id, 'on_hold_at' => null]);
    }

    public function test_checklist_is_not_created_from_an_unpaid_budget_invoice(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $this->invoice($client, $brand, 'Content Production', $manager);

        $this->assertDatabaseMissing('brand_checklists', ['brand_id' => $brand->id]);
    }

    public function test_order_does_not_matter_content_charge_first_then_budget(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $this->invoice($client, $brand, 'Content Production', $manager);
        $budget = $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $this->assertDatabaseMissing('brand_checklists', ['brand_id' => $brand->id]);

        $this->pay($budget, 1000, $manager);

        $this->assertDatabaseHas('brand_checklists', ['brand_id' => $brand->id]);
    }

    // ── On-hold trigger (Fix E) ──────────────────────────────────────────

    public function test_a_full_refund_that_zeroes_the_budget_holds_the_checklist_without_deleting_anything(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'process refunds', 'request refunds']);
        $content = $this->user('Content', ['manage raw-content']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $budget = $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $payment = $this->pay($budget, 1000, $manager);
        $this->invoice($client, $brand, 'Content Production', $manager);
        $this->assertDatabaseHas('brand_checklists', ['brand_id' => $brand->id, 'on_hold_at' => null]);

        $item = app(ContentItemService::class)->create($brand, [
            'category' => 'raw_content', 'title' => 'Pre-hold item',
        ], $content);

        // Simulate a fully-completed refund the way RefundService leaves things:
        // the payment's committed refund total equals the payment, and the
        // invoice's status is recalculated from what's actually still paid.
        Refund::create([
            'payment_id' => $payment->id, 'invoice_id' => $budget->id, 'client_id' => $client->id,
            'refund_number' => 'RF-'.uniqid(), 'amount' => 1000, 'status' => Refund::STATUS_COMPLETED,
            'reason' => 'Test', 'requested_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        $checklist = BrandChecklist::where('brand_id', $brand->id)->first();
        $this->assertNotNull($checklist->on_hold_at);
        $this->assertNotNull($checklist->on_hold_reason);

        // Nothing about the pre-hold content item was touched or removed.
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'brand_id' => $brand->id]);
        $this->assertNotSoftDeleted('content_items', ['id' => $item->id]);
    }

    public function test_cancelling_the_content_charge_invoice_also_holds_the_checklist(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $budget = $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $this->pay($budget, 1000, $manager);
        $charge = $this->invoice($client, $brand, 'Content Production', $manager);
        $this->assertDatabaseHas('brand_checklists', ['brand_id' => $brand->id, 'on_hold_at' => null]);

        app(InvoiceService::class)->update($charge, ['status' => Invoice::STATUS_CANCELLED], $manager);

        $checklist = BrandChecklist::where('brand_id', $brand->id)->first();
        $this->assertNotNull($checklist->on_hold_at);
    }

    public function test_new_work_is_refused_while_on_hold(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $budget = $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $this->pay($budget, 1000, $manager);
        $this->invoice($client, $brand, 'Content Production', $manager);

        BrandChecklist::where('brand_id', $brand->id)->first()
            ->update(['on_hold_at' => now(), 'on_hold_reason' => 'Test hold']);

        $this->expectException(ValidationException::class);
        app(ContentItemService::class)->create($brand, [
            'category' => 'raw_content', 'title' => 'Should be blocked',
        ], $content);
    }

    // ── Content item create/submit/request-revision (Fix C) ─────────────

    private function readyBrand(User $manager): array
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Ready Brand']);
        $budget = $this->invoice($client, $brand, 'Social Media Ads', $manager);
        $this->pay($budget, 1000, $manager);
        $this->invoice($client, $brand, 'Content Production', $manager);

        return [$client, $brand];
    }

    public function test_content_role_can_create_raw_content_and_advertising_content_but_not_poster(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        [, $brand] = $this->readyBrand($manager);

        $this->actingAs($content)->postJson(route('marketing.content-items.store', $brand), [
            'category' => 'raw_content', 'title' => 'Raw item',
        ])->assertOk();

        $this->actingAs($content)->postJson(route('marketing.content-items.store', $brand), [
            'category' => 'advertising_content', 'title' => 'Ad item',
        ])->assertOk();

        $this->actingAs($content)->postJson(route('marketing.content-items.store', $brand), [
            'category' => 'poster', 'title' => 'Poster item',
        ])->assertForbidden();
    }

    public function test_design_role_can_create_poster_but_not_raw_content(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $design = $this->user('Design', ['manage designer-content']);
        [, $brand] = $this->readyBrand($manager);

        $this->actingAs($design)->postJson(route('marketing.content-items.store', $brand), [
            'category' => 'poster', 'title' => 'Poster item',
        ])->assertOk();

        $this->actingAs($design)->postJson(route('marketing.content-items.store', $brand), [
            'category' => 'raw_content', 'title' => 'Raw item',
        ])->assertForbidden();
    }

    public function test_a_brand_with_no_checklist_yet_refuses_content_item_creation(): void
    {
        $content = $this->user('Content', ['manage raw-content']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'No Checklist Yet']);

        $response = $this->actingAs($content)->postJson(route('marketing.content-items.store', $brand), [
            'category' => 'raw_content', 'title' => 'Too early',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('content_items', ['title' => 'Too early']);
    }

    public function test_a_brand_can_carry_more_than_one_item_per_category(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        [, $brand] = $this->readyBrand($manager);

        $this->actingAs($content)->postJson(route('marketing.content-items.store', $brand), ['category' => 'raw_content', 'title' => 'First'])->assertOk();
        $this->actingAs($content)->postJson(route('marketing.content-items.store', $brand), ['category' => 'raw_content', 'title' => 'Second'])->assertOk();

        $this->assertSame(2, ContentItem::where('brand_id', $brand->id)->where('category', 'raw_content')->count());
    }

    public function test_submitting_a_link_moves_the_item_to_available(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        [, $brand] = $this->readyBrand($manager);

        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);
        $this->assertSame(ContentItem::STATUS_PENDING, $item->status);

        $response = $this->actingAsMaker($content, $item)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/asset.jpg',
        ]);

        $response->assertOk();
        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->fresh()->status);
        $this->assertDatabaseHas('content_item_submissions', ['content_item_id' => $item->id, 'link_url' => 'https://example.com/asset.jpg']);
    }

    public function test_submitting_a_file_uses_upload_staging_and_stores_disk(): void
    {
        Storage::fake('local');
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        [, $brand] = $this->readyBrand($manager);

        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);

        $response = $this->actingAsMaker($content, $item)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'file' => UploadedFile::fake()->create('asset.jpg', 100),
        ]);

        $response->assertOk();
        $submission = ContentItemSubmission::where('content_item_id', $item->id)->first();
        $this->assertNotNull($submission->file_path);
        $this->assertNotNull($submission->disk);
    }

    public function test_submission_is_refused_while_the_checklist_is_on_hold(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        [, $brand] = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);

        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Test hold']);

        $response = $this->actingAsMaker($content, $item)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/asset.jpg',
        ]);

        $response->assertStatus(422);
    }

    public function test_request_revision_resets_status_and_keeps_the_prior_submission_intact(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        [, $brand] = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);

        $this->actingAsMaker($content, $item)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v1.jpg',
        ])->assertOk();
        $firstSubmissionId = ContentItemSubmission::where('content_item_id', $item->id)->first()->id;

        // A waiting version is Marketing's pre-publish stage, so Marketing sends it back.
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $response = $this->actingAsStageOwner($marketing, $item)->postJson(route('marketing.content-items.request-revision', [$brand, $item]), [
            'note' => 'Wrong crop, please redo.',
        ]);
        $response->assertOk();

        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        $this->assertDatabaseHas('content_item_revisions', [
            'content_item_id' => $item->id, 'previous_status' => ContentItem::STATUS_AVAILABLE, 'requested_by' => $marketing->id,
        ]);
        // The original submission is untouched — not deleted, not overwritten.
        $this->assertDatabaseHas('content_item_submissions', ['id' => $firstSubmissionId, 'link_url' => 'https://example.com/v1.jpg']);

        // Resubmission creates a SECOND row and moves the item back to available.
        $this->actingAsMaker($content, $item)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v2.jpg',
        ])->assertOk();

        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->fresh()->status);
        $this->assertSame(2, ContentItemSubmission::where('content_item_id', $item->id)->count());
        $this->assertDatabaseHas('content_item_submissions', ['id' => $firstSubmissionId, 'link_url' => 'https://example.com/v1.jpg']);
    }

    public function test_a_role_without_review_permission_cannot_request_a_revision(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $otherContent = $this->user('Content', ['manage raw-content']);
        [, $brand] = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);

        $response = $this->actingAs($otherContent)->postJson(route('marketing.content-items.request-revision', [$brand, $item]), [
            'note' => 'Not allowed',
        ]);

        $response->assertForbidden();
    }
}
