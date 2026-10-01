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
use App\Models\Refund;
use App\Models\User;
use App\Services\AdvertisingExpenditureService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5 — financial edge cases the phase-by-phase tests didn't reach:
 * partial payments/refunds (as opposed to the full-amount scenarios Phase
 * 1/3 used), a brand that was never funded at all, and the expenditure
 * delete path (requestDelete), which had zero coverage anywhere before now.
 */
class Phase5FinancialEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manage payments', 'manage advertising-expenditure'] as $perm) {
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

    private function adInvoice(Client $client, Brand $brand, User $manager, float $totalPayable = 1000): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => $totalPayable, 'status' => Invoice::STATUS_UNPAID,
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

    public function test_a_partial_payment_alone_triggers_the_checklist_and_contributes_only_its_own_amount(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $invoice = $this->adInvoice($client, $brand, $manager, 1000);
        $this->pay($invoice, 400, $manager); // partial — not the full 1000

        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->fresh()->status);
        $this->assertSame(400.0, $brand->fresh()->advertisingBudget());

        // Content-charge invoice completes eligibility — checklist should now exist
        // even though the ad-budget invoice is only partially paid.
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 200, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        $this->assertNotNull($brand->fresh()->checklist);
        $this->assertFalse($brand->fresh()->checklist->isOnHold());
    }

    public function test_a_second_partial_payment_accumulates_toward_the_full_budget(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $invoice = $this->adInvoice($client, $brand, $manager, 1000);
        $this->pay($invoice, 400, $manager);
        $this->pay($invoice->fresh(), 600, $manager);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1000.0, $brand->fresh()->advertisingBudget());
    }

    public function test_a_partial_refund_reduces_but_does_not_zero_the_budget_and_does_not_hold_an_existing_checklist(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $invoice = $this->adInvoice($client, $brand, $manager, 1000);
        $payment = $this->pay($invoice, 1000, $manager);
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 200, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        $checklist = $brand->fresh()->checklist;
        $this->assertNotNull($checklist);

        // Refund only part of what was paid.
        Refund::create([
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'client_id' => $brand->client_id,
            'refund_number' => 'RF-' . uniqid(), 'amount' => 300, 'status' => Refund::STATUS_COMPLETED,
            'reason' => 'Partial refund test', 'requested_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh());

        $this->assertSame(700.0, $brand->fresh()->advertisingBudget());
        $this->assertFalse($checklist->fresh()->isOnHold());
    }

    public function test_a_brand_that_was_never_funded_cannot_record_expenditure_and_has_no_checklist(): void
    {
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);

        $this->assertSame(0.0, $brand->advertisingBudget());
        $this->assertFalse($brand->hasAvailableAdvertisingBudget());
        $this->assertNull($brand->checklist);

        $this->expectException(ValidationException::class);
        app(AdvertisingExpenditureService::class)->create($brand, [
            'amount' => 10, 'reporting_date' => now()->toDateString(),
        ], $marketing);
    }

    public function test_a_privileged_user_can_delete_an_expenditure_immediately(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'manage advertising-expenditure']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $this->pay($this->adInvoice($client, $brand, $manager, 1000), 1000, $manager);

        $expenditure = app(AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 200, 'reporting_date' => now()->toDateString(), 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $manager);
        $this->assertSame(200.0, $brand->fresh()->advertisingSpent());

        $response = $this->actingAs($manager)->deleteJson(
            route('marketing.expenditures.destroy', [$brand, $expenditure]),
            ['reason' => 'Recorded in error, removing it.']
        );

        $response->assertOk();
        $response->assertJson(['applied' => true]);

        // Soft-deleted, not gone — stops counting toward live totals...
        $this->assertSoftDeleted('advertising_expenditures', ['id' => $expenditure->id]);
        $this->assertSame(0.0, $brand->fresh()->advertisingSpent());
        $this->assertEqualsWithDelta(1000.0, $brand->fresh()->advertisingRemaining(), 0.001);
        $this->assertFalse($brand->fresh()->isAdvertisingOverspent());

        // ...but the FULL original row, including fields the PendingChange/
        // ActivityLog snapshot never captured (recorded_by, idempotency_key,
        // created_at), is still recoverable for audit via withTrashed().
        $trashed = AdvertisingExpenditure::withTrashed()->findOrFail($expenditure->id);
        $this->assertSame($manager->id, $trashed->recorded_by);
        $this->assertNotNull($trashed->idempotency_key);
        $this->assertNotNull($trashed->created_at);
        $this->assertSame('200.00', $trashed->amount);
    }

    public function test_a_non_privileged_delete_request_queues_and_the_amount_stays_counted_until_approved(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $this->pay($this->adInvoice($client, $brand, $manager, 1000), 1000, $manager);

        $expenditure = app(AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 200, 'reporting_date' => now()->toDateString(),
        ], $marketing);

        $response = $this->actingAs($marketing)->deleteJson(
            route('marketing.expenditures.destroy', [$brand, $expenditure]),
            ['reason' => 'Think this was a duplicate entry.']
        );

        $response->assertStatus(202);
        $response->assertJson(['applied' => false, 'pending' => true]);
        $this->assertDatabaseHas('advertising_expenditures', ['id' => $expenditure->id, 'deleted_at' => null]);
        $this->assertSame(200.0, $brand->fresh()->advertisingSpent()); // still counted — nothing approved yet

        $change = PendingChange::where('model_type', AdvertisingExpenditure::class)->where('model_id', $expenditure->id)->firstOrFail();
        app(\App\Services\AdvertisingExpenditureService::class)->approveChange($change, $manager);

        $this->assertSoftDeleted('advertising_expenditures', ['id' => $expenditure->id]);
        $this->assertSame(0.0, $brand->fresh()->advertisingSpent());
    }

    public function test_deleting_an_expenditure_preserves_existing_authorization_pendingchange_and_activitylog_behavior(): void
    {
        $content = $this->user('Content'); // no 'manage advertising-expenditure' at all
        $manager = $this->user('Manager', ['manage payments', 'manage advertising-expenditure']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $this->pay($this->adInvoice($client, $brand, $manager, 1000), 1000, $manager);

        $expenditure = app(AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 50, 'reporting_date' => now()->toDateString(),
        ], $manager);

        // Authorization is unchanged — a role without the permission is still refused.
        $this->actingAs($content)->deleteJson(
            route('marketing.expenditures.destroy', [$brand, $expenditure]),
            ['reason' => 'Trying anyway.']
        )->assertForbidden();
        $this->assertDatabaseHas('advertising_expenditures', ['id' => $expenditure->id, 'deleted_at' => null]);

        $this->actingAs($manager)->deleteJson(
            route('marketing.expenditures.destroy', [$brand, $expenditure]),
            ['reason' => 'Recorded in error.']
        )->assertOk();

        // PendingChange and ActivityLog behavior is unchanged.
        $this->assertDatabaseHas('pending_changes', [
            'model_type' => AdvertisingExpenditure::class, 'model_id' => $expenditure->id, 'status' => 'applied',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'module' => 'Advertising Expenditure', 'action' => 'Deleted', 'client_id' => $client->id,
        ]);
    }
}
