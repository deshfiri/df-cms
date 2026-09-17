<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\User;
use App\Notifications\RefundNeedsAttention;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Refunds: requested, decided by someone else, paid out with a reference —
 * never more than was paid, never twice at once, never skipping a step.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $accounts;
    private User $manager;
    private User $director;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Permissions and role grants come from the refunds migration; the
        // roles themselves are created here, as the test database has none.
        foreach (['manage payments', 'view payments', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['request refunds', 'approve refunds', 'process refunds'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web'])
            ->syncPermissions(['manage payments', 'view payments', 'view clients', 'request refunds', 'approve refunds', 'process refunds']);
        Role::firstOrCreate(['name' => 'Accounts', 'guard_name' => 'web'])
            ->syncPermissions(['manage payments', 'view payments', 'view clients', 'request refunds', 'process refunds']);

        $this->accounts = tap(User::factory()->create(['is_active' => true, 'name' => 'Accounts Ali']))->assignRole('Accounts')->fresh();
        $this->manager  = tap(User::factory()->create(['is_active' => true, 'name' => 'Manager Mina']))->assignRole('Manager')->fresh();
        $this->director = tap(User::factory()->create(['is_active' => true, 'name' => 'Manager Dev']))->assignRole('Manager')->fresh();

        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $this->client = Client::create(['dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME', 'category_id' => $category->id]);
    }

    /** ৳20,000 charge with ৳10,000 received against it. */
    private function payment(float $amount = 10000): Payment
    {
        $this->actingAs($this->accounts)->postJson(route('clients.payments.store', $this->client), [
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'charge_total' => 20000, 'charge_title' => 'October ads', 'amount' => $amount,
        ])->assertOk();

        return Payment::latest('id')->first();
    }

    private function ask(Payment $payment, float $amount, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->accounts)->postJson(route('payments.refunds.store', $payment), [
            'amount' => $amount, 'reason' => 'Campaign cancelled before launch', 'method' => 'bKash',
        ]);
    }

    private function step(string $action, Refund $refund, User $as, array $data = [])
    {
        return $this->actingAs($as)->postJson(route("refunds.{$action}", $refund), $data);
    }

    // ── Asking ───────────────────────────────────────────────────────────

    public function test_a_refund_is_requested_and_approvers_are_told(): void
    {
        $payment = $this->payment();

        $this->ask($payment, 4000)
            ->assertCreated()
            ->assertJsonPath('refund.status', 'requested')
            ->assertJsonPath('refund.amount', '4000.00')
            ->assertJsonPath('refund.payment.refundable', '6000.00')
            ->assertJsonPath('refund.can.approve', false);

        $refund = Refund::sole();
        $this->assertMatchesRegularExpression('/^RF-\d{6}-\d{5}$/', $refund->refund_number);
        $this->assertSame($this->accounts->id, $refund->requested_by);
        $this->assertSame($payment->invoice_id, $refund->invoice_id);
        $this->assertSame(['requested'], RefundEvent::pluck('to_status')->all());

        Notification::assertSentTo([$this->manager, $this->director], RefundNeedsAttention::class);
        Notification::assertNotSentTo($this->accounts, RefundNeedsAttention::class);
    }

    public function test_nobody_can_ask_for_more_than_was_paid(): void
    {
        $payment = $this->payment();

        $this->ask($payment, 10000.01)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(0, Refund::count());
    }

    public function test_money_already_on_its_way_back_counts_against_the_next_request(): void
    {
        $payment = $this->payment();
        $first = $this->ask($payment, 6000)->assertCreated()->json('refund');
        $this->step('approve', Refund::find($first['id']), $this->manager)->assertOk();
        $this->step('process', Refund::find($first['id']), $this->accounts)->assertOk();
        $this->step('complete', Refund::find($first['id']), $this->accounts, ['reference' => 'BK-1001'])->assertOk();

        $this->ask($payment, 4000.01)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'At most ৳4,000.00 can still be refunded from this payment.']);
        $this->ask($payment, 4000)->assertCreated();
    }

    public function test_only_one_refund_per_payment_is_in_progress_at_a_time(): void
    {
        $payment = $this->payment();
        $this->ask($payment, 1000)->assertCreated();

        $this->ask($payment, 1000)->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame(1, Refund::count());
    }

    public function test_money_that_was_never_received_cannot_be_refunded(): void
    {
        $this->actingAs($this->accounts)->postJson(route('clients.payments.store', $this->client), ['status' => 'Unpaid', 'amount' => 5000])->assertOk();

        $this->ask(Payment::sole(), 100)->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_a_payment_waiting_on_a_correction_cannot_be_refunded_yet(): void
    {
        $payment = $this->payment();
        app(PaymentService::class)->requestUpdate($payment, ['amount' => 9000], 'Wrong amount', $this->accounts);

        $this->ask($payment, 100)->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_asking_needs_the_permission_and_a_reason(): void
    {
        $payment = $this->payment();
        $viewer = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view payments');

        $this->ask($payment, 100, $viewer)->assertForbidden();
        $this->actingAs($this->accounts)->postJson(route('payments.refunds.store', $payment), ['amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    // ── Deciding ─────────────────────────────────────────────────────────

    public function test_nobody_decides_on_their_own_request_not_even_a_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $owner = tap(User::factory()->create(['is_active' => true]))->assignRole('Super Admin')->fresh();
        $payment = $this->payment();

        $refund = Refund::find($this->ask($payment, 500, $owner)->assertCreated()->json('refund.id'));

        $this->step('approve', $refund, $owner)
            ->assertForbidden()
            ->assertJson(['message' => 'You asked for this refund, so someone else has to decide on it.']);
        $this->step('review', $refund, $owner)->assertForbidden();
        $this->assertSame('requested', $refund->fresh()->status);

        $this->step('approve', $refund, $this->manager)->assertOk()->assertJsonPath('refund.status', 'approved');
    }

    public function test_someone_without_approve_refunds_cannot_decide(): void
    {
        $refund = Refund::find($this->ask($this->payment(), 500, $this->manager)->json('refund.id'));

        $this->step('approve', $refund, $this->accounts)->assertForbidden();
        $this->step('reject', $refund, $this->accounts, ['note' => 'No'])->assertForbidden();
    }

    public function test_a_rejection_needs_a_reason_and_frees_the_money(): void
    {
        $payment = $this->payment();
        $refund  = Refund::find($this->ask($payment, 10000)->json('refund.id'));

        $this->step('reject', $refund, $this->manager)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->step('reject', $refund, $this->manager, ['note' => 'Campaign ran — nothing to refund'])
            ->assertOk()
            ->assertJsonPath('refund.status', 'rejected')
            ->assertJsonPath('refund.decided_by', 'Manager Mina');

        Notification::assertSentTo($this->accounts, RefundNeedsAttention::class);
        $this->ask($payment, 10000)->assertCreated();
    }

    public function test_approval_rechecks_the_payment_as_it_stands(): void
    {
        $payment = $this->payment();
        $refund  = Refund::find($this->ask($payment, 8000)->json('refund.id'));

        // The payment shrinks by some other route after the request.
        Payment::whereKey($payment->id)->update(['amount' => 5000]);

        $this->step('approve', $refund, $this->manager)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame('requested', $refund->fresh()->status);
    }

    // ── Paying out ───────────────────────────────────────────────────────

    public function test_the_full_life_of_a_refund_is_recorded_step_by_step(): void
    {
        $payment = $this->payment();
        $refund  = Refund::find($this->ask($payment, 10000)->json('refund.id'));

        $this->step('review', $refund, $this->manager, ['note' => 'Checking the ad account'])->assertOk()->assertJsonPath('refund.status', 'under_review');
        $this->step('approve', $refund, $this->director, ['note' => 'Confirmed'])->assertOk();
        $this->step('process', $refund, $this->accounts, ['method' => 'Bank transfer'])->assertOk()->assertJsonPath('refund.status', 'processing');

        $this->step('complete', $refund, $this->accounts)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $done = $this->step('complete', $refund, $this->accounts, ['reference' => 'TXN-7788'])
            ->assertOk()
            ->assertJsonPath('refund.status', 'completed')
            ->assertJsonPath('refund.reference', 'TXN-7788')
            ->assertJsonPath('refund.method', 'Bank transfer')
            ->json('refund');

        $this->assertSame(['requested', 'under_review', 'approved', 'processing', 'completed'], array_column($done['events'], 'to'));
        $this->assertSame(['Accounts Ali', 'Manager Mina', 'Manager Dev', 'Accounts Ali', 'Accounts Ali'], array_column($done['events'], 'by'));
        $this->assertNotNull($refund->fresh()->completed_at);

        // Every taka of the charge's money went back: it is closed as Refunded.
        $charge = Invoice::sole();
        $this->assertSame(0.0, $charge->paid_amount);
        $this->assertSame(10000.0, $charge->refunded_amount);
        $this->assertSame(Invoice::STATUS_REFUNDED, $charge->status);
    }

    public function test_a_partial_refund_nets_the_charge_and_the_client_totals(): void
    {
        $payment = $this->payment();
        $refund  = Refund::find($this->ask($payment, 2500)->json('refund.id'));
        $this->step('approve', $refund, $this->manager);
        $this->step('process', $refund, $this->accounts);

        // Not counted as gone until it is paid out.
        $this->assertSame(10000.0, Invoice::sole()->paid_amount);

        $this->step('complete', $refund, $this->accounts, ['reference' => 'BK-55'])->assertOk();

        $charge = Invoice::query()->withPaidTotal()->sole();
        $this->assertSame(7500.0, $charge->paid_amount);
        $this->assertSame(12500.0, $charge->due_amount);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $charge->status);

        $this->actingAs($this->accounts)->getJson(route('clients.payments.index', $this->client))
            ->assertOk()
            ->assertJsonPath('summary.total_paid', 10000)
            ->assertJsonPath('summary.total_refunded', 2500)
            ->assertJsonPath('summary.net_received', 7500)
            ->assertJsonPath('summary.by_category.0.received', 7500)
            ->assertJsonPath('summary.by_category.0.refunded', 2500)
            ->assertJsonPath('payments.0.refunded_amount', '2500.00')
            ->assertJsonPath('payments.0.refundable_amount', '7500.00');
    }

    public function test_steps_cannot_be_skipped_or_repeated(): void
    {
        $refund = Refund::find($this->ask($this->payment(), 1000)->json('refund.id'));

        $this->step('process', $refund, $this->accounts)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->step('complete', $refund, $this->accounts, ['reference' => 'X-1'])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->step('approve', $refund, $this->manager)->assertOk();
        $this->step('approve', $refund, $this->director)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->step('reject', $refund, $this->director, ['note' => 'Changed my mind'])->assertUnprocessable();

        $this->step('process', $refund, $this->accounts)->assertOk();
        $this->step('cancel', $refund, $this->manager, ['reason' => 'Too late'])->assertUnprocessable();
        $this->step('complete', $refund, $this->accounts, ['reference' => 'X-1'])->assertOk();
        $this->step('cancel', $refund, $this->manager, ['reason' => 'Too late'])->assertUnprocessable();

        $this->assertSame(4, RefundEvent::count());
    }

    public function test_paying_out_needs_process_refunds(): void
    {
        $refund = Refund::find($this->ask($this->payment(), 1000)->json('refund.id'));
        $this->step('approve', $refund, $this->manager)->assertOk();

        $viewer = tap(User::factory()->create(['is_active' => true]))->givePermissionTo(['view payments', 'approve refunds']);
        $this->step('process', $refund, $viewer)->assertForbidden();
    }

    // ── Cancelling ───────────────────────────────────────────────────────

    public function test_the_requester_can_withdraw_before_a_decision_and_others_cannot(): void
    {
        $other = tap(User::factory()->create(['is_active' => true]))->assignRole('Accounts');
        $refund = Refund::find($this->ask($this->payment(), 1000)->json('refund.id'));

        $this->step('cancel', $refund, $other, ['reason' => 'Not mine'])->assertForbidden();
        $this->step('cancel', $refund, $this->accounts)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->step('cancel', $refund, $this->accounts, ['reason' => 'Client changed their mind'])
            ->assertOk()
            ->assertJsonPath('refund.status', 'cancelled')
            ->assertJsonPath('refund.cancel_reason', 'Client changed their mind');
    }

    public function test_once_approved_only_an_approver_can_cancel(): void
    {
        $refund = Refund::find($this->ask($this->payment(), 1000)->json('refund.id'));
        $this->step('approve', $refund, $this->manager)->assertOk();

        $this->step('cancel', $refund, $this->accounts, ['reason' => 'Oops'])->assertForbidden();
        $this->step('cancel', $refund, $this->director, ['reason' => 'Paid in cash instead'])->assertOk();
    }

    // ── The payment is pinned ────────────────────────────────────────────

    public function test_a_payment_with_refunds_cannot_be_deleted_or_shrunk_below_them(): void
    {
        $payment = $this->payment();
        $this->ask($payment, 3000)->assertCreated();

        $this->actingAs($this->manager)
            ->deleteJson(route('clients.payments.destroy', [$this->client, $payment]), ['reason' => 'Mistake'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->actingAs($this->manager)
            ->putJson(route('clients.payments.update', [$this->client, $payment]), ['amount' => 2000, 'reason' => 'Correction'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertNotNull($payment->fresh());
    }

    // ── Pages ────────────────────────────────────────────────────────────

    public function test_the_refunds_page_lists_and_counts(): void
    {
        $payment = $this->payment();
        $this->ask($payment, 1000)->assertCreated();

        $this->actingAs($this->manager)->get(route('refunds.index'))->assertOk()->assertSee('Refunds');

        $this->actingAs($this->manager)
            ->getJson(route('refunds.index', ['status' => 'requested']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('counts.status.requested', 1)
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->accounts)->getJson(route('clients.refunds.index', $this->client))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can.cancel', true);

        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->get(route('refunds.index'))->assertForbidden();
    }
}
