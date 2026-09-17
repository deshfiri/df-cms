<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PendingChange;
use App\Models\User;
use App\Notifications\ChangeAwaitingApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Correcting or deleting a payment: requested with a reason, approved by
 * someone else, re-checked when approved, and recorded for good.
 */
class PaymentCorrectionTest extends TestCase
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

        foreach (['manage payments', 'view payments', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web'])->givePermissionTo(['manage payments', 'view payments', 'view clients']);

        $this->accounts = tap(User::factory()->create(['is_active' => true, 'name' => 'Accounts Ali']))
            ->givePermissionTo(['manage payments', 'view payments', 'view clients'])->fresh();
        $this->manager  = tap(User::factory()->create(['is_active' => true, 'name' => 'Manager Mina']))->assignRole('Manager')->fresh();
        $this->director = tap(User::factory()->create(['is_active' => true, 'name' => 'Manager Dev']))->assignRole('Manager')->fresh();

        $this->client = $this->makeClient();
    }

    private function makeClient(): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create(['dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME', 'category_id' => $category->id]);
    }

    /** ৳20,000 Social Media Ads charge, ৳10,000 paid against it. */
    private function payment(): Payment
    {
        $this->actingAs($this->accounts)->postJson(route('clients.payments.store', $this->client), [
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'charge_total' => 20000, 'charge_title' => 'October ads', 'amount' => 10000,
            'payment_date' => '2026-09-10', 'payment_method' => 'bKash',
        ])->assertOk();

        return Payment::sole();
    }

    private function edit(User $as, Payment $payment, array $data)
    {
        return $this->actingAs($as)->putJson(route('clients.payments.update', [$this->client, $payment]), $data);
    }

    // ── Requesting ───────────────────────────────────────────────────────

    public function test_a_non_approvers_correction_waits_and_changes_nothing(): void
    {
        $payment = $this->payment();

        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'Client sent ৳2,000 more the same day'])
            ->assertStatus(202)
            ->assertJson(['success' => true, 'applied' => false, 'pending' => true]);

        $this->assertSame('10000.00', $payment->fresh()->amount);

        $change = PendingChange::sole();
        $this->assertSame(PendingChange::STATUS_PENDING, $change->status);
        $this->assertSame(['client_id' => $this->client->id, 'amount' => '10000.00'], $change->old_values);
        $this->assertSame(['amount' => '12000.00'], $change->new_values);
        $this->assertSame('Client sent ৳2,000 more the same day', $change->reason);
        $this->assertSame($this->accounts->id, $change->requested_by);

        Notification::assertSentTo([$this->manager, $this->director], ChangeAwaitingApproval::class);
        Notification::assertNotSentTo($this->accounts, ChangeAwaitingApproval::class);
    }

    public function test_a_reason_is_required(): void
    {
        $payment = $this->payment();

        $this->edit($this->accounts, $payment, ['amount' => 12000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, PendingChange::count());
    }

    public function test_asking_for_what_is_already_recorded_is_refused(): void
    {
        $payment = $this->payment();

        $this->edit($this->accounts, $payment, ['amount' => '10000', 'payment_method' => 'bKash', 'reason' => 'Just checking'])
            ->assertUnprocessable();

        $this->assertSame(0, PendingChange::count());
    }

    public function test_a_second_request_cannot_overwrite_the_first(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'First'])->assertStatus(202);

        $this->edit($this->accounts, $payment, ['amount' => 9000, 'reason' => 'Second'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame(['amount' => '12000.00'], PendingChange::sole()->new_values);
    }

    public function test_a_request_that_could_never_apply_is_refused_up_front(): void
    {
        $payment = $this->payment();

        $this->edit($this->accounts, $payment, ['amount' => 25000, 'reason' => 'More came in'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, PendingChange::count());
        $this->assertSame('10000.00', $payment->fresh()->amount);
    }

    // ── Reviewing ────────────────────────────────────────────────────────

    public function test_approving_applies_it_and_records_who_and_when(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 20000, 'reason' => 'Paid in full'])->assertStatus(202);
        $change = PendingChange::sole();

        $this->travel(5)->minutes();
        $this->actingAs($this->manager)->postJson(route('pending-changes.approve', $change), ['note' => 'Checked the statement'])
            ->assertOk();

        $this->assertSame('20000.00', $payment->fresh()->amount);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::sole()->status);

        $change->refresh();
        $this->assertSame(PendingChange::STATUS_APPROVED, $change->status);
        $this->assertSame($this->manager->id, $change->reviewed_by);
        $this->assertNotNull($change->reviewed_at);
        $this->assertNotNull($change->applied_at);
        $this->assertSame('Checked the statement', $change->review_note);
    }

    public function test_nobody_reviews_their_own_request(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'More came in'])->assertStatus(202);

        // Promoted to Manager after asking — still not their call.
        $this->accounts->assignRole('Manager');

        $this->actingAs($this->accounts->fresh())->postJson(route('pending-changes.approve', PendingChange::sole()))
            ->assertForbidden()
            ->assertJson(['message' => 'You cannot review your own request. Another approver has to.']);

        $this->assertSame(PendingChange::STATUS_PENDING, PendingChange::sole()->status);
        $this->assertSame('10000.00', $payment->fresh()->amount);
    }

    public function test_approving_a_request_made_against_values_that_have_since_changed_is_refused(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'More came in'])->assertStatus(202);
        $request = PendingChange::sole();

        // Meanwhile the amount changes by some other route — a data fix, an import.
        Payment::whereKey($payment->id)->update(['amount' => 11000]);

        $this->actingAs($this->manager)->postJson(route('pending-changes.approve', $request))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('change');

        $this->assertSame('11000.00', $payment->fresh()->amount);
        $this->assertSame(PendingChange::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_a_change_is_reviewed_once(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'More came in'])->assertStatus(202);
        $change = PendingChange::sole();

        $this->actingAs($this->manager)->postJson(route('pending-changes.reject', $change), ['note' => 'No proof'])->assertOk();
        $this->actingAs($this->director)->postJson(route('pending-changes.approve', $change))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('change');

        $this->assertSame(PendingChange::STATUS_REJECTED, $change->fresh()->status);
        $this->assertSame('No proof', $change->fresh()->review_note);
        $this->assertSame('10000.00', $payment->fresh()->amount);
    }

    public function test_an_approver_cannot_edit_past_a_request_that_is_still_waiting(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'More came in'])->assertStatus(202);

        $this->edit($this->manager, $payment, ['amount' => 11000, 'reason' => 'Bank says 11,000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame('10000.00', $payment->fresh()->amount);
    }

    public function test_only_approvers_can_review(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'More came in'])->assertStatus(202);
        $other = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage payments');

        $this->actingAs($other)->postJson(route('pending-changes.approve', PendingChange::sole()))->assertForbidden();
    }

    public function test_an_approvers_own_correction_applies_and_is_still_recorded(): void
    {
        $payment = $this->payment();

        $this->edit($this->manager, $payment, ['payment_method' => 'Bank transfer', 'transaction_number' => 'TXN-88', 'reason' => 'Wrong method entered'])
            ->assertOk()
            ->assertJson(['applied' => true]);

        $this->assertSame('Bank transfer', $payment->fresh()->payment_method);

        $change = PendingChange::sole();
        $this->assertSame(PendingChange::STATUS_APPLIED, $change->status);
        $this->assertSame($this->manager->id, $change->requested_by);
        $this->assertSame($this->manager->id, $change->reviewed_by);
        $this->assertSame(['client_id' => $this->client->id, 'payment_method' => 'bKash', 'transaction_number' => null], $change->old_values);
    }

    // ── Deleting ─────────────────────────────────────────────────────────

    public function test_a_deletion_waits_for_approval_and_the_record_survives_it(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->accounts)
            ->deleteJson(route('clients.payments.destroy', [$this->client, $payment]), ['reason' => 'Duplicate entry'])
            ->assertStatus(202);
        $this->assertNotNull($payment->fresh());

        $this->actingAs($this->manager)->postJson(route('pending-changes.approve', PendingChange::sole()))->assertOk();

        $this->assertNull($payment->fresh());
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::sole()->status);

        $history = $this->actingAs($this->accounts)
            ->getJson(route('clients.payments.history', [$this->client, $payment->id]))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $history);
        $this->assertSame('delete', $history[0]['action']);
        $this->assertSame('Approved and applied', $history[0]['status_label']);
        $this->assertSame('10000.00', $history[0]['snapshot']['amount']);
        $this->assertSame('Duplicate entry', $history[0]['reason']);
        $this->assertSame('Accounts Ali', $history[0]['requested_by']);
        $this->assertSame('Manager Mina', $history[0]['reviewed_by']);
    }

    public function test_the_payments_page_delete_follows_the_same_rule(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->accounts)->deleteJson(route('payments.destroy', $payment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->accounts)->deleteJson(route('payments.destroy', $payment), ['reason' => 'Test entry'])
            ->assertStatus(202);

        $this->assertNotNull($payment->fresh());
    }

    // ── The trail ────────────────────────────────────────────────────────

    public function test_the_history_tells_the_whole_story_newest_first(): void
    {
        $payment = $this->payment();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'First try'])->assertStatus(202);
        $this->actingAs($this->manager)->postJson(route('pending-changes.reject', PendingChange::sole()), ['note' => 'No proof']);
        $this->travel(1)->minutes();
        $this->edit($this->accounts, $payment, ['amount' => 12000, 'reason' => 'Statement attached'])->assertStatus(202);

        $history = $this->actingAs($this->accounts)
            ->getJson(route('clients.payments.history', [$this->client, $payment->id]))
            ->assertOk()
            ->json('data');

        $this->assertSame(['Waiting for approval', 'Rejected'], array_column($history, 'status_label'));
        $this->assertSame([['field' => 'amount', 'label' => 'Amount', 'from' => '10000.00', 'to' => '12000.00']], $history[0]['changes']);
        $this->assertSame('No proof', $history[1]['review_note']);
    }

    public function test_a_payments_history_is_not_reachable_through_another_client(): void
    {
        $payment = $this->payment();
        $this->edit($this->manager, $payment, ['payment_method' => 'Cash', 'reason' => 'Fix'])->assertOk();
        $other = $this->makeClient();

        $this->actingAs($this->manager)->getJson(route('clients.payments.history', [$other, $payment->id]))->assertNotFound();
    }
}
