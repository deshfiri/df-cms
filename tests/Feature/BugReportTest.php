<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\User;
use App\Notifications\BugReportResolved;
use App\Notifications\BugReportSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BugReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'manage bug reports', 'guard_name' => 'web']);
    }

    /** 'manage bug reports' is the only permission bug reports take. */
    private function makeUser(?string $role = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        if ($role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
            if ($role === 'Manager') {
                $user->givePermissionTo('manage bug reports');
            }
        }

        return $user;
    }

    // ── Who may use bug reports at all ───────────────────────────────────

    public function test_anyone_signed_in_can_open_the_page_and_file_one(): void
    {
        // No role, no permissions: reporting a bug is part of having a login.
        $nobody = User::factory()->create(['is_active' => true]);

        $this->actingAs($nobody)->get(route('bug-reports.index'))
            ->assertOk()
            ->assertSee('data-bs-target="#newBugModal"', false);

        $this->actingAs($nobody)->getJson(route('bug-reports.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->actingAs($nobody)
            ->postJson(route('bug-reports.store'), ['subject' => 'Broken button', 'message' => 'It does nothing', 'severity' => 'Low'])
            ->assertOk();

        $this->assertDatabaseHas('bug_reports', ['subject' => 'Broken button', 'reported_by' => $nobody->id]);
    }

    public function test_the_sidebar_offers_bug_reports_to_everyone(): void
    {
        $manager = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage bug reports');

        foreach ([User::factory()->create(['is_active' => true]), $this->makeUser('Accounts'), $manager->fresh()] as $user) {
            $this->actingAs($user)->get(route('my-work'))
                ->assertOk()
                ->assertSee(route('bug-reports.index'), false);
        }
    }

    public function test_withdrawing_your_own_open_report_needs_nothing_extra(): void
    {
        $employee = User::factory()->create(['is_active' => true]);
        $open     = BugReport::create(['subject' => 'Still open', 'message' => 'msg', 'reported_by' => $employee->id]);

        $this->actingAs($employee)->deleteJson(route('bug-reports.destroy', $open))->assertOk();
        $this->assertSoftDeleted('bug_reports', ['id' => $open->id]);
    }

    public function test_you_still_cannot_touch_someone_elses_report(): void
    {
        $owner    = User::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['is_active' => true]);
        $theirs   = BugReport::create(['subject' => 'Private', 'message' => 'msg', 'reported_by' => $owner->id]);

        $this->actingAs($stranger)->deleteJson(route('bug-reports.destroy', $theirs))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('bug-reports.respond', $theirs), ['status' => 'Resolved'])->assertForbidden();

        $list = $this->actingAs($stranger)->getJson(route('bug-reports.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertFalse(collect($list->json('data'))->pluck('subject')->contains('Private'));
    }

    // ── Filing and responding ────────────────────────────────────────────

    public function test_any_authenticated_user_can_submit_a_report(): void
    {
        Notification::fake();
        $employee = $this->makeUser('Sales');

        $response = $this->actingAs($employee)->postJson(route('bug-reports.store'), [
            'subject'  => 'Dashboard chart is blank',
            'message'  => 'The revenue chart never loads on the dashboard.',
            'severity' => 'High',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('bug_reports', [
            'subject'     => 'Dashboard chart is blank',
            'reported_by' => $employee->id,
            'severity'    => 'High',
            'status'      => BugReport::STATUS_OPEN,
        ]);
    }

    public function test_a_severity_outside_the_known_list_is_rejected(): void
    {
        $employee = $this->makeUser('Sales');

        $this->actingAs($employee)->postJson(route('bug-reports.store'), [
            'subject' => 'X', 'message' => 'Y', 'severity' => 'Catastrophic',
        ])->assertStatus(422)->assertJsonValidationErrors('severity');
    }

    public function test_a_non_manager_only_sees_their_own_reports_in_the_list(): void
    {
        $employeeA = $this->makeUser('Sales');
        $employeeB = $this->makeUser('Sales');

        BugReport::create(['subject' => 'From A', 'message' => 'msg', 'reported_by' => $employeeA->id]);
        BugReport::create(['subject' => 'From B', 'message' => 'msg', 'reported_by' => $employeeB->id]);

        $response = $this->actingAs($employeeA)->getJson(route('bug-reports.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();

        $subjects = collect($response->json('data'))->pluck('subject');
        $this->assertTrue($subjects->contains('From A'));
        $this->assertFalse($subjects->contains('From B'));
    }

    public function test_a_manager_sees_all_reports_and_can_resolve_one(): void
    {
        Notification::fake();
        $employee = $this->makeUser('Sales');
        $manager  = $this->makeUser('Manager');

        // Submitted through the endpoint (not Eloquent directly) so the
        // reviewer notification, which the service fires on create, actually runs.
        $this->actingAs($employee)->postJson(route('bug-reports.store'), [
            'subject' => 'Export button missing', 'message' => 'msg', 'severity' => 'Medium',
        ])->assertOk();
        $report = BugReport::where('subject', 'Export button missing')->firstOrFail();

        $listResponse = $this->actingAs($manager)->getJson(route('bug-reports.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $listResponse->assertOk();
        $this->assertTrue(collect($listResponse->json('data'))->pluck('subject')->contains('Export button missing'));

        $respondResponse = $this->actingAs($manager)->postJson(route('bug-reports.respond', $report), [
            'status' => 'Resolved',
            'note'   => 'Shipped in the next release.',
        ]);

        $respondResponse->assertOk()->assertJson(['success' => true]);
        $report->refresh();
        $this->assertSame(BugReport::STATUS_RESOLVED, $report->status);
        $this->assertSame($manager->id, $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);
        $this->assertSame('Shipped in the next release.', $report->response_note);

        Notification::assertSentTo($manager, BugReportSubmitted::class);
        Notification::assertSentTo($employee, BugReportResolved::class);
    }

    public function test_a_report_can_also_be_closed_as_not_a_bug(): void
    {
        Notification::fake();
        $employee = $this->makeUser('Sales');
        $manager  = $this->makeUser('Manager');
        $report   = BugReport::create(['subject' => 'Actually intended', 'message' => 'msg', 'reported_by' => $employee->id]);

        $this->actingAs($manager)->postJson(route('bug-reports.respond', $report), [
            'status' => 'Closed', 'note' => 'Working as designed.',
        ])->assertOk();

        $this->assertSame(BugReport::STATUS_CLOSED, $report->fresh()->status);
        Notification::assertSentTo($employee, BugReportResolved::class);
    }

    public function test_responding_twice_to_the_same_report_is_rejected(): void
    {
        $employee = $this->makeUser('Sales');
        $manager  = $this->makeUser('Manager');

        $report = BugReport::create([
            'subject' => 'Already handled', 'message' => 'msg', 'reported_by' => $employee->id,
            'status' => BugReport::STATUS_RESOLVED, 'reviewed_by' => $manager->id, 'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($manager)->postJson(route('bug-reports.respond', $report), ['status' => 'Closed']);

        $response->assertStatus(422);
    }

    public function test_a_user_without_manage_bug_reports_permission_cannot_respond(): void
    {
        $employee = $this->makeUser('Sales');
        $other    = $this->makeUser('Sales');

        $report = BugReport::create(['subject' => 'Something', 'message' => 'msg', 'reported_by' => $employee->id]);

        $response = $this->actingAs($other)->postJson(route('bug-reports.respond', $report), ['status' => 'Resolved']);

        $response->assertStatus(403);
    }

    public function test_a_reporter_can_delete_their_own_open_report_but_not_once_resolved(): void
    {
        $employee = $this->makeUser('Sales');

        $open = BugReport::create(['subject' => 'Still open', 'message' => 'msg', 'reported_by' => $employee->id]);
        $resolved = BugReport::create([
            'subject' => 'Done', 'message' => 'msg', 'reported_by' => $employee->id,
            'status' => BugReport::STATUS_RESOLVED,
        ]);

        $this->actingAs($employee)->deleteJson(route('bug-reports.destroy', $open))->assertOk();
        $this->assertSoftDeleted('bug_reports', ['id' => $open->id]);

        $this->actingAs($employee)->deleteJson(route('bug-reports.destroy', $resolved))->assertStatus(403);
    }

    public function test_every_change_is_logged(): void
    {
        $manager = $this->makeUser('Manager');

        $this->actingAs($manager)->postJson(route('bug-reports.store'), [
            'subject' => 'Logged bug', 'message' => 'msg', 'severity' => 'Low',
        ])->assertOk();
        $report = BugReport::where('subject', 'Logged bug')->firstOrFail();

        $this->actingAs($manager)->postJson(route('bug-reports.respond', $report), ['status' => 'Resolved'])->assertOk();
        $this->actingAs($manager)->deleteJson(route('bug-reports.destroy', $report))->assertOk();

        $this->assertDatabaseHas('activity_logs', ['module' => 'Bug Report', 'action' => 'Submitted']);
        $this->assertDatabaseHas('activity_logs', ['module' => 'Bug Report', 'action' => 'Bug Report Resolved']);
        $this->assertDatabaseHas('activity_logs', ['module' => 'Bug Report', 'action' => 'Deleted']);
    }
}
