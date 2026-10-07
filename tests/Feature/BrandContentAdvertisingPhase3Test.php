<?php

namespace Tests\Feature;

use App\Models\AdvertisingExpenditure;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PendingChange;
use App\Models\PublishedContent;
use App\Models\Refund;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3 of the Brand Content & Advertising DMS integration: content-charge
 * entry, advertising expenditure (gated on the budget itself, deliberately
 * independent of the checklist's hold state — see the plan's Option 2
 * decision), and publishing review. See
 * C:\Users\DST\.claude\plans\soft-nibbling-quilt.md.
 */
class BrandContentAdvertisingPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'view clients', 'manage payments',
            'manage content-charges', 'manage advertising-expenditure', 'manage publishing-review',
            'manage raw-content', 'manage smm-collection', 'manage published-content',
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
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    /** A brand with a paid advertising budget — no content charge, no checklist. */
    private function fundedBrand(User $manager, float $amount = 1000): Brand
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $budget = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => $amount, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        $payment = Payment::create([
            'client_id' => $client->id, 'invoice_id' => $budget->id, 'payment_category_id' => $budget->payment_category_id,
            'amount' => $amount, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        return $brand->fresh();
    }

    /** Also gives the brand a content-charge invoice, so InvoiceObserver creates its checklist. */
    private function readyBrand(User $manager, float $amount = 1000): Brand
    {
        $brand = $this->fundedBrand($manager, $amount);
        Invoice::create([
            'client_id' => $brand->client_id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 500, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    private function refundInFull(Brand $brand, User $manager): void
    {
        $budget = Invoice::where('brand_id', $brand->id)
            ->whereHas('category', fn ($q) => $q->where('name', 'Social Media Ads'))
            ->firstOrFail();
        $payment = Payment::where('invoice_id', $budget->id)->firstOrFail();

        Refund::create([
            'payment_id' => $payment->id, 'invoice_id' => $budget->id, 'client_id' => $brand->client_id,
            'refund_number' => 'RF-'.uniqid(), 'amount' => $payment->amount, 'status' => Refund::STATUS_COMPLETED,
            'reason' => 'Test refund', 'requested_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());
    }

    // ── Content charge entry ─────────────────────────────────────────────

    public function test_marketing_can_record_a_content_charge_without_touching_payment(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage content-charges']);
        $brand = $this->fundedBrand($manager);

        $response = $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brand), [
            'total_payable' => 500, 'title' => 'October content',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoices', ['brand_id' => $brand->id, 'total_payable' => 500, 'status' => Invoice::STATUS_UNPAID]);
        $this->assertDatabaseCount('payments', 1); // only the budget payment from fundedBrand(), none from this call
    }

    public function test_a_role_without_content_charge_permission_cannot_record_one(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content');
        $brand = $this->fundedBrand($manager);

        $response = $this->actingAs($content)->postJson(route('marketing.content-charge.store', $brand), [
            'total_payable' => 500,
        ]);

        $response->assertForbidden();
    }

    // ── Advertising expenditure: budget-based gate (Option 2) ───────────

    public function test_expenditure_can_be_recorded_when_budget_is_paid_but_no_checklist_exists_yet(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager);
        $this->assertNull($brand->checklist);

        $response = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('advertising_expenditures', ['brand_id' => $brand->id, 'amount' => '100.00']);
    }

    public function test_expenditure_can_still_be_recorded_while_the_checklist_is_on_hold(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->readyBrand($manager);
        $this->assertNotNull($brand->fresh()->checklist);

        $brand->fresh()->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Unrelated content hold']);

        $response = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('advertising_expenditures', ['brand_id' => $brand->id, 'amount' => '100.00']);
    }

    public function test_expenditure_is_rejected_once_the_budget_is_refunded_to_zero(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager);

        $this->refundInFull($brand, $manager);
        $this->assertSame(0.0, $brand->fresh()->advertisingBudget());

        $response = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('advertising_expenditures', ['brand_id' => $brand->id]);
    }

    public function test_existing_expenditure_history_is_unaffected_when_the_checklist_later_goes_on_hold(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->readyBrand($manager);

        $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 250, 'reporting_date' => now()->toDateString(),
        ])->assertOk();

        $brand->fresh()->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Later hold']);

        $this->assertDatabaseHas('advertising_expenditures', ['brand_id' => $brand->id, 'amount' => '250.00']);
        $this->assertSame(250.0, $brand->fresh()->advertisingSpent());
    }

    public function test_remaining_budget_goes_negative_when_overspent_rather_than_floored(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager, 100);

        $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 60, 'reporting_date' => now()->toDateString(),
        ])->assertOk();
        $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 70, 'reporting_date' => now()->toDateString(),
        ])->assertOk();

        $brand = $brand->fresh();
        $this->assertSame(100.0, $brand->advertisingBudget());
        $this->assertSame(130.0, $brand->advertisingSpent());
        $this->assertSame(-30.0, $brand->advertisingRemaining());
        $this->assertTrue($brand->isAdvertisingOverspent());
        $this->assertSame(30.0, $brand->advertisingOverspentAmount());
    }

    // ── Idempotency + soft duplicate guard (Fix D) ───────────────────────

    public function test_the_same_idempotency_key_never_creates_a_second_row(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager);

        $payload = ['amount' => 100, 'reporting_date' => now()->toDateString(), 'idempotency_key' => 'key-abc-123'];

        $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), $payload)->assertOk();
        $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), $payload)->assertOk();

        $this->assertSame(1, AdvertisingExpenditure::where('brand_id', $brand->id)->count());
    }

    public function test_a_near_duplicate_without_confirmation_is_refused_then_allowed_with_it(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager);
        $date = now()->toDateString();

        $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => $date,
        ])->assertOk();

        $second = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => $date,
        ]);
        $second->assertStatus(422);

        $confirmed = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => $date, 'confirm_duplicate' => true,
        ]);
        $confirmed->assertOk();

        $this->assertSame(2, AdvertisingExpenditure::where('brand_id', $brand->id)->count());
    }

    // ── Corrections via PendingChange (Fix D) ────────────────────────────

    public function test_a_non_privileged_correction_queues_for_approval_and_does_not_move_the_budget_yet(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'manage advertising-expenditure']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager, 1000);

        $created = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ])->json('data');

        $response = $this->actingAs($marketing)->putJson(route('marketing.expenditures.update', [$brand, $created['id']]), [
            'amount' => 400, 'reason' => 'Typo — should have been 400.',
        ]);

        $response->assertStatus(202);
        $this->assertSame(100.0, $brand->fresh()->advertisingSpent());
        $this->assertDatabaseHas('pending_changes', [
            'model_type' => AdvertisingExpenditure::class, 'model_id' => $created['id'], 'status' => PendingChange::STATUS_PENDING,
        ]);
    }

    public function test_approving_a_correction_through_the_shared_pending_changes_queue_moves_the_budget(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'manage advertising-expenditure']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager, 1000);

        $created = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ])->json('data');

        $this->actingAs($marketing)->putJson(route('marketing.expenditures.update', [$brand, $created['id']]), [
            'amount' => 400, 'reason' => 'Typo — should have been 400.',
        ])->assertStatus(202);

        $change = PendingChange::where('model_type', AdvertisingExpenditure::class)->where('model_id', $created['id'])->firstOrFail();

        $this->actingAs($manager)->postJson(route('pending-changes.approve', $change))->assertOk();

        $this->assertSame(400.0, $brand->fresh()->advertisingSpent());
    }

    public function test_a_privileged_user_correction_applies_immediately(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'manage advertising-expenditure']);
        $brand = $this->fundedBrand($manager, 1000);

        $created = $this->actingAs($manager)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ])->json('data');

        $response = $this->actingAs($manager)->putJson(route('marketing.expenditures.update', [$brand, $created['id']]), [
            'amount' => 400, 'reason' => 'Manager fixing it directly.',
        ]);

        $response->assertOk();
        $response->assertJson(['applied' => true]);
        $this->assertSame(400.0, $brand->fresh()->advertisingSpent());
    }

    // ── Publishing review ─────────────────────────────────────────────────

    private function publishedContentFor(Brand $brand, User $content, User $smm): PublishedContent
    {
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);
        app(ContentItemService::class)->submit($item, ['link_url' => 'https://example.com/v1.jpg'], $content);
        $item = $item->fresh();
        app(ContentItemService::class)->approveForHandover($item, $item->latestSubmission(), $this->user('Marketing', ['manage publishing-review']));
        app(ContentItemService::class)->collect($item, $smm);
        $item = $item->fresh();

        return app(ContentItemService::class)->publish(
            $item, $brand, $item->latestSubmission(), ['facebook_post_url' => 'https://facebook.com/post/1'], $smm,
        );
    }

    public function test_marketing_can_mark_one_published_item_reviewed_without_touching_another(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $reviewMe = $this->publishedContentFor($brand, $content, $smm);
        $leaveAlone = $this->publishedContentFor($brand, $content, $smm);

        $response = $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $reviewMe]));

        $response->assertOk();
        $this->assertNotNull($reviewMe->fresh()->reviewed_at);
        $this->assertNull($leaveAlone->fresh()->reviewed_at);
    }

    public function test_unreviewed_listing_only_shows_unreviewed_items_for_that_brand(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $reviewed = $this->publishedContentFor($brand, $content, $smm);
        $unreviewed = $this->publishedContentFor($brand, $content, $smm);
        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $reviewed]))->assertOk();

        $response = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brand));

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($reviewed->id, $ids);
        $this->assertContains($unreviewed->id, $ids);
    }

    public function test_a_role_without_publishing_review_permission_cannot_review(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        $published = $this->publishedContentFor($brand, $content, $smm);

        $response = $this->actingAs($smm)->postJson(route('marketing.published-contents.review', [$brand, $published]));

        $response->assertForbidden();
    }
}
