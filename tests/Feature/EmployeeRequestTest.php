<?php

namespace Tests\Feature;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestResolved;
use App\Notifications\RequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Employee requests, sent to whoever they're addressed to.
 *
 * Filing one no longer broadcasts to everyone holding "manage requests" —
 * the requester picks one or more specific people, and only the requester
 * and those people can see or act on it. "manage requests" plays no part in
 * that any more; Super Admin still sees everything, but only via the
 * unconditional Gate::before, never anything checked here.
 */
class EmployeeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function file(User $from, array $to, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($from)->postJson(route('requests.store'), array_merge([
            'subject'       => 'Need a new laptop',
            'message'       => 'My current one is too slow.',
            'recipient_ids' => collect($to)->pluck('id')->all(),
        ], $overrides));
    }

    // ── Who may use requests at all ──────────────────────────────────────

    public function test_anyone_signed_in_can_open_the_page_and_file_one(): void
    {
        $nobody = $this->user();
        $manager = $this->user();

        $this->actingAs($nobody)->get(route('requests.index'))
            ->assertOk()
            ->assertSee('data-bs-target="#newRequestModal"', false);

        $this->actingAs($nobody)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->file($nobody, [$manager], ['subject' => 'Laptop'])->assertOk();

        $this->assertDatabaseHas('employee_requests', ['subject' => 'Laptop', 'requested_by' => $nobody->id]);
    }

    public function test_the_page_offers_a_recipient_picker_listing_every_active_user_but_yourself(): void
    {
        $viewer = $this->user();
        $other  = tap($this->user())->update(['name' => 'Pick Me']);
        $inactive = tap($this->user())->update(['name' => 'Gone Now', 'is_active' => false]);

        $response = $this->actingAs($viewer)->get(route('requests.index'))->assertOk();

        $response->assertSee('id="reqRecipients"', false)
            ->assertSee('Only the people you pick here will see this request.')
            ->assertSee('value="' . $other->id . '">Pick Me', false)
            ->assertDontSee('value="' . $viewer->id . '"', false)
            ->assertDontSee('Gone Now');
    }

    public function test_the_list_always_shows_who_filed_it_and_who_it_was_sent_to(): void
    {
        $requester = tap($this->user())->update(['name' => 'Requester Rex']);
        $recipient = tap($this->user())->update(['name' => 'Recipient Rae']);

        $this->file($requester, [$recipient], ['subject' => 'Column check'])->assertOk();

        $response = $this->actingAs($requester)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $row = collect($response->json('data'))->firstWhere('subject', 'Column check');

        $this->assertSame('Requester Rex', $row['requester']);
        $this->assertSame('Recipient Rae <span style="color:var(--text3)">(Pending)</span>', $row['recipients']);
    }

    public function test_the_sidebar_offers_requests_to_everyone(): void
    {
        foreach ([$this->user(), $this->user(), $this->user()] as $someone) {
            $this->actingAs($someone)->get(route('my-work'))
                ->assertOk()
                ->assertSee(route('requests.index'), false);
        }
    }

    public function test_a_department_worker_gets_the_requests_menu_too(): void
    {
        // Stage workers used to be given a trimmed menu that dropped Requests.
        Permission::firstOrCreate(['name' => 'submit-stage', 'guard_name' => 'web']);
        $worker = tap($this->user())->givePermissionTo('submit-stage');

        $this->actingAs($worker->fresh())->get(route('my-work'))
            ->assertOk()
            ->assertSee(route('requests.index'), false)
            ->assertSee(route('my-work'), false);
    }

    // ── Filing requires someone to send it to ────────────────────────────

    public function test_filing_without_a_recipient_is_rejected(): void
    {
        $employee = $this->user();

        $this->actingAs($employee)->postJson(route('requests.store'), [
            'subject' => 'Need a laptop', 'message' => 'Please',
        ])->assertStatus(422)->assertJsonValidationErrors('recipient_ids');
    }

    public function test_you_cannot_send_a_request_to_yourself(): void
    {
        $employee = $this->user();

        $this->file($employee, [$employee])->assertStatus(422)->assertJsonValidationErrors('recipient_ids.0');
    }

    public function test_filing_with_one_or_more_recipients_succeeds(): void
    {
        Notification::fake();
        $employee = $this->user();
        $managerA = $this->user();
        $managerB = $this->user();

        $this->file($employee, [$managerA, $managerB])->assertOk()->assertJson(['success' => true]);

        $request = EmployeeRequest::firstOrFail();
        $this->assertSame([$managerA->id, $managerB->id], $request->recipients->pluck('id')->sort()->values()->all());

        Notification::assertSentTo($managerA, RequestSubmitted::class);
        Notification::assertSentTo($managerB, RequestSubmitted::class);
    }

    // ── Visibility: only the requester and the selected recipients ──────

    public function test_a_request_is_visible_only_to_its_requester_and_its_recipients(): void
    {
        $requester = $this->user();
        $recipient = $this->user();
        $bystander = $this->user();

        $this->file($requester, [$recipient], ['subject' => 'Addressed'])->assertOk();

        foreach ([$requester, $recipient] as $who) {
            $subjects = collect(
                $this->actingAs($who)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                    ->assertOk()->json('data')
            )->pluck('subject');
            $this->assertTrue($subjects->contains('Addressed'));
        }

        $subjects = collect(
            $this->actingAs($bystander)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->pluck('subject');
        $this->assertFalse($subjects->contains('Addressed'));
    }

    /** The load-bearing rule this whole change exists for. */
    public function test_manage_requests_permission_alone_grants_no_visibility(): void
    {
        Permission::firstOrCreate(['name' => 'manage requests', 'guard_name' => 'web']);
        $requester = $this->user();
        $recipient = $this->user();
        $manager   = tap($this->user())->givePermissionTo('manage requests')->fresh(); // not a recipient

        $this->file($requester, [$recipient], ['subject' => 'Not for the manager'])->assertOk();
        $request = EmployeeRequest::where('subject', 'Not for the manager')->firstOrFail();

        $subjects = collect(
            $this->actingAs($manager)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->pluck('subject');
        $this->assertFalse($subjects->contains('Not for the manager'));

        $this->actingAs($manager)->postJson(route('requests.respond', $request), ['status' => 'Approved'])
            ->assertForbidden();
    }

    // ── Responding: only a selected recipient ────────────────────────────

    public function test_a_selected_recipient_can_approve_regardless_of_permissions(): void
    {
        Notification::fake();
        $employee  = $this->user();
        $recipient = $this->user(); // holds nothing special — being named is the authorization

        $this->file($employee, [$recipient])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($recipient)->postJson(route('requests.respond', $request), [
            'status' => 'Approved', 'note' => 'Go ahead.',
        ])->assertOk()->assertJson(['success' => true]);

        $request->refresh();
        $this->assertSame(EmployeeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($recipient->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame('Go ahead.', $request->response_note);

        Notification::assertSentTo($employee, RequestResolved::class);
    }

    /** The bug this whole change exists to fix: one recipient answering must not shut the others out. */
    public function test_one_recipients_approval_leaves_the_request_open_for_the_others(): void
    {
        $employee = $this->user();
        $a = $this->user();
        $b = $this->user();

        $this->file($employee, [$a, $b])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $this->assertSame(EmployeeRequest::STATUS_PENDING, $request->fresh()->status, 'still waiting on b');

        $this->actingAs($b)->postJson(route('requests.respond', $request), ['status' => 'Rejected'])->assertOk();

        $this->assertSame(EmployeeRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_it_only_becomes_approved_once_every_recipient_has_approved(): void
    {
        Notification::fake();
        $employee = $this->user();
        $a = $this->user();
        $b = $this->user();

        $this->file($employee, [$a, $b])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();
        $this->assertSame(EmployeeRequest::STATUS_PENDING, $request->fresh()->status);
        // Not resolved yet — the requester isn't told about a partial approval.
        Notification::assertNotSentTo($employee, RequestResolved::class);

        $this->actingAs($b)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $this->assertSame(EmployeeRequest::STATUS_APPROVED, $request->fresh()->status);
        Notification::assertSentTo($employee, RequestResolved::class);
    }

    public function test_a_recipient_who_already_answered_cannot_answer_again(): void
    {
        $employee = $this->user();
        $a = $this->user();
        $b = $this->user();

        $this->file($employee, [$a, $b])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Rejected'])
            ->assertStatus(422);
    }

    public function test_a_rejection_still_closes_it_outright_for_whoever_has_not_answered_yet(): void
    {
        $employee = $this->user();
        $a = $this->user();
        $b = $this->user();

        $this->file($employee, [$a, $b])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Rejected'])->assertOk();
        $this->assertSame(EmployeeRequest::STATUS_REJECTED, $request->fresh()->status);

        $this->actingAs($b)->postJson(route('requests.respond', $request), ['status' => 'Approved'])
            ->assertStatus(422);
    }

    /** Each recipient sees only their own name and their own answer — never who else it went to or how they answered. */
    public function test_a_recipient_sees_only_their_own_name_and_status_not_the_others(): void
    {
        $employee = $this->user();
        $a = tap($this->user())->update(['name' => 'Anika']);
        $b = tap($this->user())->update(['name' => 'Bashir']);

        $this->file($employee, [$a, $b])->assertOk();
        $request = EmployeeRequest::firstOrFail();
        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $row = collect(
            $this->actingAs($b)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->firstOrFail();

        $this->assertSame('Bashir', $row['recipients']);
        $this->assertStringNotContainsString('Anika', $row['recipients']);
        $this->assertStringContainsString('Pending', $row['status_badge']);
        $this->assertStringNotContainsString('Approved', $row['status_badge']);
    }

    /** The requester sees everyone and how each one answered, converging on "Approved by All" once they all have. */
    public function test_the_requester_sees_every_recipients_answer_and_the_approved_by_all_label(): void
    {
        $employee = $this->user();
        $a = tap($this->user())->update(['name' => 'Anika']);
        $b = tap($this->user())->update(['name' => 'Bashir']);

        $this->file($employee, [$a, $b])->assertOk();
        $request = EmployeeRequest::firstOrFail();
        $this->actingAs($a)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $row = fn () => collect(
            $this->actingAs($employee)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->firstOrFail();

        $partial = $row();
        $this->assertStringContainsString('Anika', $partial['recipients']);
        $this->assertStringContainsString('Bashir', $partial['recipients']);
        $this->assertStringContainsString('Approved', $partial['recipients']);
        $this->assertStringNotContainsString('Approved by All', $partial['status_badge']);
        $this->assertStringContainsString('Pending', $partial['status_badge']);

        $this->actingAs($b)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $this->assertStringContainsString('Approved by All', $row()['status_badge']);
    }

    public function test_someone_not_sent_the_request_cannot_respond(): void
    {
        $employee  = $this->user();
        $recipient = $this->user();
        $stranger  = $this->user();

        $this->file($employee, [$recipient])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($stranger)->postJson(route('requests.respond', $request), ['status' => 'Approved'])
            ->assertStatus(403);
    }

    /** The requester filed it, but approving their own ask isn't theirs to do. */
    public function test_the_requester_cannot_approve_their_own_request_unless_also_a_recipient(): void
    {
        $employee  = $this->user();
        $recipient = $this->user();

        $this->file($employee, [$recipient])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($employee)->postJson(route('requests.respond', $request), ['status' => 'Approved'])
            ->assertStatus(403);
    }

    // ── Withdrawing ───────────────────────────────────────────────────────

    public function test_withdrawing_your_own_pending_request_needs_nothing_extra(): void
    {
        $employee = $this->user();
        $recipient = $this->user();

        $this->file($employee, [$recipient])->assertOk();
        $pending = EmployeeRequest::firstOrFail();

        $this->actingAs($employee)->deleteJson(route('requests.destroy', $pending))->assertOk();
        $this->assertSoftDeleted('employee_requests', ['id' => $pending->id]);
    }

    public function test_a_recipient_cannot_delete_a_request_sent_to_them(): void
    {
        $employee  = $this->user();
        $recipient = $this->user();

        $this->file($employee, [$recipient])->assertOk();
        $request = EmployeeRequest::firstOrFail();

        $this->actingAs($recipient)->deleteJson(route('requests.destroy', $request))->assertForbidden();
    }

    public function test_you_still_cannot_touch_someone_elses_request(): void
    {
        $owner     = $this->user();
        $recipient = $this->user();
        $stranger  = $this->user();

        $this->file($owner, [$recipient], ['subject' => 'Private'])->assertOk();
        $theirs = EmployeeRequest::where('subject', 'Private')->firstOrFail();

        $this->actingAs($stranger)->deleteJson(route('requests.destroy', $theirs))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('requests.respond', $theirs), ['status' => 'Approved'])->assertForbidden();

        $list = $this->actingAs($stranger)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertFalse(collect($list->json('data'))->pluck('subject')->contains('Private'));
    }

    public function test_a_requester_can_delete_their_own_pending_request_but_not_once_resolved(): void
    {
        $employee  = $this->user();
        $recipient = $this->user();

        $this->file($employee, [$recipient], ['subject' => 'Still open'])->assertOk();
        $pending = EmployeeRequest::where('subject', 'Still open')->firstOrFail();

        $this->actingAs($recipient)->postJson(route('requests.respond', $pending), ['status' => 'Approved'])->assertOk();

        $this->actingAs($employee)->deleteJson(route('requests.destroy', $pending))->assertStatus(403);
    }
}
