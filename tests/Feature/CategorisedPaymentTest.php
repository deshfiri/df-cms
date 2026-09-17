<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\PaymentService;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Categorised billing with partial payments.
 *
 * The case it exists for: a client owes ৳20,000 for Social Media Ads and pays
 * ৳10,000 now. That is one charge under a category, one payment against it, and
 * a balance of ৳10,000 that every later payment, correction or deletion keeps
 * honest.
 */
class CategorisedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $accounts;
    private Client $client;
    private PaymentCategory $ads;
    private PaymentCategory $web;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['manage payments', 'view payments', 'manage clients', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Accounts: records money, doesn't own clients.
        $this->accounts = $this->user('manage payments', 'view payments', 'view clients');
        $this->client   = $this->client();

        // Seeded by the migration.
        $this->ads = PaymentCategory::where('name', 'Social Media Ads')->firstOrFail();
        $this->web = PaymentCategory::where('name', 'Website Development')->firstOrFail();
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function client(string $name = 'ACME Ltd'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    private function record(array $payload, ?Client $client = null)
    {
        return $this->actingAs($this->accounts)
            ->postJson(route('clients.payments.store', $client ?? $this->client), $payload);
    }

    /** ৳20,000 Social Media Ads charge with ৳10,000 paid against it, via the endpoint. */
    private function adsChargeHalfPaid(): Invoice
    {
        $this->record([
            'payment_category_id' => $this->ads->id,
            'charge_total'        => 20000,
            'charge_title'        => 'Facebook ads — October',
            'amount'              => 10000,
            'payment_method'      => 'bKash',
        ])->assertOk();

        return Invoice::where('client_id', $this->client->id)->latest('id')->firstOrFail();
    }

    // ── The reported case ────────────────────────────────────────────────

    public function test_a_category_can_be_billed_and_part_paid_in_one_step(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->assertSame($this->ads->id, $charge->payment_category_id);
        $this->assertSame(20000.0, (float) $charge->total_payable);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $charge->status);
        $this->assertSame(10000.0, $charge->paid_amount);
        $this->assertSame(10000.0, $charge->due_amount);

        $payment = Payment::sole();
        $this->assertSame($charge->id, $payment->invoice_id);
        $this->assertSame($this->ads->id, $payment->payment_category_id);
        // Money received is Paid; the charge is what's partial.
        $this->assertSame('Paid', $payment->status);
    }

    public function test_paying_the_balance_settles_the_charge(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->record(['invoice_id' => $charge->id, 'amount' => 10000])->assertOk();

        $charge->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $charge->status);
        $this->assertSame(0.0, $charge->due_amount);
    }

    public function test_a_payment_takes_its_charges_category_whatever_was_sent(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->record(['invoice_id' => $charge->id, 'payment_category_id' => $this->web->id, 'amount' => 500, 'status' => 'Unpaid'])
            ->assertOk();

        $payment = Payment::latest('id')->first();
        $this->assertSame($this->ads->id, $payment->payment_category_id);
        $this->assertSame('Paid', $payment->status);
    }

    // ── What a charge refuses ────────────────────────────────────────────

    public function test_paying_more_than_is_due_is_refused(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->record(['invoice_id' => $charge->id, 'amount' => 10000.01])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(1, Payment::count());
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $charge->fresh()->status);
    }

    public function test_a_new_charge_cannot_start_overpaid(): void
    {
        $this->record(['payment_category_id' => $this->ads->id, 'charge_total' => 5000, 'amount' => 6000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        // The charge opened for it rolls back with the payment.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_against_a_charge_needs_an_amount(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->record(['invoice_id' => $charge->id])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->record(['invoice_id' => $charge->id, 'amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_another_clients_charge_is_refused(): void
    {
        $other  = $this->client('Other Co');
        $charge = Invoice::create([
            'client_id' => $other->id, 'invoice_number' => 'INV-X-1', 'total_payable' => 1000,
            'status' => Invoice::STATUS_UNPAID, 'issued_by' => $this->accounts->id, 'issued_date' => today(),
        ]);

        $this->record(['invoice_id' => $charge->id, 'amount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_id');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_cancelled_charge_takes_no_payments(): void
    {
        $charge = $this->adsChargeHalfPaid();
        $charge->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->record(['invoice_id' => $charge->id, 'amount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_id');
    }

    public function test_an_inactive_category_takes_no_new_charges(): void
    {
        $this->ads->update(['is_active' => false]);

        $this->record(['payment_category_id' => $this->ads->id, 'charge_total' => 1000, 'amount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_category_id');
    }

    // ── Keeping the balance honest ───────────────────────────────────────

    public function test_deleting_a_payment_puts_the_balance_back(): void
    {
        $charge = $this->adsChargeHalfPaid();
        $this->record(['invoice_id' => $charge->id, 'amount' => 10000])->assertOk();
        $this->assertSame(Invoice::STATUS_PAID, $charge->fresh()->status);

        // Deletions are approved corrections now (see PaymentCorrectionTest);
        // an approver's own applies at once.
        $approver = $this->privileged();
        $last = Payment::latest('id')->first();
        $this->actingAs($approver)
            ->deleteJson(route('clients.payments.destroy', [$this->client, $last]), ['reason' => 'Entered twice'])
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $charge->fresh()->status);

        $this->actingAs($approver)->deleteJson(route('payments.destroy', Payment::sole()), ['reason' => 'Bounced'])->assertOk();
        $this->assertSame(Invoice::STATUS_UNPAID, $charge->fresh()->status);
    }

    public function test_correcting_an_amount_recalculates_and_still_caps_at_the_total(): void
    {
        $charge  = $this->adsChargeHalfPaid();
        $payment = Payment::sole();
        $approver = $this->privileged();
        $this->actingAs($approver);
        $service = app(PaymentService::class);

        $service->requestUpdate($payment, ['amount' => 20000], 'Paid in full', $approver);
        $this->assertSame(Invoice::STATUS_PAID, $charge->fresh()->status);

        // Its own earlier amount doesn't count against it, but the total still does.
        $this->expectException(ValidationException::class);
        $service->requestUpdate($payment->fresh(), ['amount' => 20000.5], 'Typo', $approver);
    }

    public function test_moving_a_payment_between_charges_recalculates_both(): void
    {
        $ads = $this->adsChargeHalfPaid();
        $this->record(['payment_category_id' => $this->web->id, 'charge_total' => 50000, 'amount' => 1000])->assertOk();
        $web = Invoice::where('payment_category_id', $this->web->id)->sole();

        $approver = $this->privileged();
        $this->actingAs($approver);
        app(PaymentService::class)->requestUpdate(Payment::where('invoice_id', $ads->id)->sole(), ['invoice_id' => $web->id], 'Wrong charge', $approver);

        $this->assertSame(Invoice::STATUS_UNPAID, $ads->fresh()->status);
        $this->assertSame(11000.0, $web->fresh()->paid_amount);
        $this->assertSame($this->web->id, Payment::where('invoice_id', $web->id)->first()->payment_category_id);
    }

    public function test_a_charge_total_cannot_drop_below_what_was_received(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->actingAs($this->accounts)
            ->putJson(route('clients.invoices.update', [$this->client, $charge]), ['total_payable' => 9000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('total_payable');

        $this->assertSame(20000.0, (float) $charge->fresh()->total_payable);
    }

    public function test_raising_a_settled_charges_total_reopens_it(): void
    {
        $charge = $this->adsChargeHalfPaid();
        $this->record(['invoice_id' => $charge->id, 'amount' => 10000])->assertOk();

        $this->actingAs($this->accounts)
            ->putJson(route('clients.invoices.update', [$this->client, $charge]), ['total_payable' => 25000])
            ->assertOk()
            ->assertJsonPath('data.status', Invoice::STATUS_PARTIALLY_PAID)
            ->assertJsonPath('data.due_amount', 5000);
    }

    public function test_recategorising_a_charge_moves_its_payments_with_it(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->actingAs($this->accounts)
            ->putJson(route('clients.invoices.update', [$this->client, $charge]), ['payment_category_id' => $this->web->id])
            ->assertOk();

        $this->assertSame($this->web->id, Payment::sole()->payment_category_id);
    }

    public function test_a_charge_with_payments_is_cancelled_not_deleted(): void
    {
        $charge = $this->adsChargeHalfPaid();

        $this->actingAs($this->accounts)
            ->deleteJson(route('clients.invoices.destroy', [$this->client, $charge]))
            ->assertStatus(422);

        $this->assertNotNull($charge->fresh());
    }

    // ── What existed before still works ──────────────────────────────────

    public function test_a_standalone_payment_keeps_its_own_status(): void
    {
        $this->record(['status' => 'Partial', 'amount' => 3000])->assertOk();

        $payment = Payment::sole();
        $this->assertSame('Partial', $payment->status);
        $this->assertNull($payment->invoice_id);
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_standalone_payment_must_say_its_status(): void
    {
        $this->record(['amount' => 3000])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_payments_page_can_record_against_a_new_charge(): void
    {
        $this->actingAs($this->accounts)
            ->postJson(route('payments.store'), [
                'client_id'           => $this->client->id,
                'payment_category_id' => $this->ads->id,
                'charge_total'        => 20000,
                'amount'              => 10000,
            ])
            ->assertOk();

        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, Invoice::sole()->status);
    }

    public function test_a_payment_under_one_client_url_cannot_touch_another_clients_payment(): void
    {
        $other   = $this->client('Other Co');
        $payment = Payment::create(['client_id' => $other->id, 'status' => 'Paid', 'amount' => 10]);

        $this->actingAs($this->accounts)
            ->deleteJson(route('clients.payments.destroy', [$this->client, $payment]))
            ->assertNotFound();

        $this->assertNotNull($payment->fresh());
    }

    // ── The picture per category ─────────────────────────────────────────

    public function test_the_payments_tab_breaks_money_down_by_category(): void
    {
        $this->adsChargeHalfPaid();
        $this->record(['payment_category_id' => $this->web->id, 'charge_total' => 50000, 'amount' => 50000])->assertOk();
        $this->record(['status' => 'Paid', 'amount' => 700])->assertOk();

        $response = $this->actingAs($this->accounts)
            ->getJson(route('clients.payments.index', $this->client))
            ->assertOk()
            ->assertJsonPath('summary.total_billed', 70000)
            ->assertJsonPath('summary.total_paid', 60700)
            ->assertJsonPath('summary.total_outstanding', 10000)
            ->assertJsonPath('summary.open_charges', 1)
            ->assertJsonCount(2, 'charges');

        $rows = collect($response->json('summary.by_category'))->keyBy('name');

        $this->assertEquals(
            ['billed' => 20000, 'received' => 10000, 'due' => 10000, 'percent' => 50],
            collect($rows['Social Media Ads'])->only('billed', 'received', 'due', 'percent')->all(),
        );
        $this->assertEquals(0, $rows['Website Development']['due']);
        $this->assertEquals(700, $rows['Uncategorised']['received']);
    }

    public function test_open_charges_can_be_listed_for_the_picker(): void
    {
        $open = $this->adsChargeHalfPaid();
        $this->record(['payment_category_id' => $this->web->id, 'charge_total' => 1000, 'amount' => 1000])->assertOk();

        $this->actingAs($this->accounts)
            ->getJson(route('clients.invoices.index', [$this->client, 'open' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.due_amount', 10000)
            ->assertJsonPath('data.0.category.name', 'Social Media Ads');
    }

    // ── Settings → Payment Categories ────────────────────────────────────

    public function test_categories_are_managed_by_whoever_manages_payments(): void
    {
        $this->actingAs($this->accounts)->get(route('payment-categories.index'))->assertOk()->assertSee('Social Media Ads');

        $this->actingAs($this->accounts)
            ->postJson(route('payment-categories.store'), ['name' => 'Photography'])
            ->assertOk();
        $this->assertDatabaseHas('payment_categories', ['name' => 'Photography', 'is_active' => true]);

        $viewer = $this->user('view payments');
        $this->actingAs($viewer)->get(route('payment-categories.index'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('payment-categories.store'), ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_used_category_is_deactivated_not_deleted(): void
    {
        $this->adsChargeHalfPaid();

        $this->actingAs($this->accounts)
            ->deleteJson(route('payment-categories.destroy', $this->ads))
            ->assertStatus(422);

        $this->actingAs($this->accounts)
            ->putJson(route('payment-categories.update', $this->ads), ['is_active' => false])
            ->assertOk();

        $this->assertFalse($this->ads->fresh()->is_active);

        // An unused one can go.
        $this->actingAs($this->accounts)->deleteJson(route('payment-categories.destroy', $this->web))->assertOk();
        $this->assertNull($this->web->fresh());
    }

    public function test_the_payments_page_renders_with_categories(): void
    {
        $this->adsChargeHalfPaid();

        $this->actingAs($this->accounts)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('By category')
            ->assertSee('Social Media Ads');

        $this->actingAs($this->accounts)
            ->getJson(route('payments.index', ['category_id' => $this->ads->id]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
    }

    /** The client page asks about every module, and Spatie throws for a permission that doesn't exist. */
    private function allPermissions(): void
    {
        foreach ([
            'delete clients', 'manage products', 'manage documents', 'manage-workflow', 'submit-stage', 'approve-stage',
            'import clients', 'export clients', 'manage users', 'manage categories', 'view reports', 'view tasks',
            'manage tasks', 'manage-meetings', 'view file-manager', 'manage file-manager', 'view reviews',
            'manage requests', 'view ads', 'manage ads', 'view performance', 'manage performance', 'monitor chats',
            'manage workflows', 'view whatsapp', 'reply whatsapp', 'assign whatsapp', 'view all whatsapp',
            'manage whatsapp numbers', 'manage whatsapp templates', 'manage whatsapp settings',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_client_page_offers_charges_and_payments_to_accounts(): void
    {
        $this->allPermissions();

        $this->actingAs($this->accounts)
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertSee('id="newChargeBtn"', false)
            ->assertSee('id="recordPaymentBtn"', false)
            ->assertSee('window.RecordPayment', false)
            ->assertSee('var canManageMoney = true;', false);
    }

    public function test_the_client_page_hides_money_actions_from_viewers(): void
    {
        $this->allPermissions();
        $viewer = $this->user('view clients');

        $this->actingAs($viewer)
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertDontSee('id="recordPaymentBtn"', false)
            ->assertSee('var canManageMoney = false;', false);
    }

    // ── The legacy workflow gate ─────────────────────────────────────────

    public function test_a_part_paid_charge_still_reads_as_partial_to_the_workflow_gate(): void
    {
        $product = WorkflowStage::firstOrCreate(['code' => 'product_sourcing'], [
            'name' => 'Product Sourcing', 'department' => 'Admin',
            'requires_approval' => false, 'sort_order' => 5, 'status' => true,
        ]);

        $charge = $this->adsChargeHalfPaid();
        $gate   = app(WorkflowService::class);

        $this->assertNotNull($gate->getPaymentBlockReason($this->client->id, $product));

        $this->record(['invoice_id' => $charge->id, 'amount' => 10000])->assertOk();
        $this->assertNull($gate->getPaymentBlockReason($this->client->id, $product));
    }

    private function privileged(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        return tap($this->user())->assignRole('Super Admin');
    }
}
