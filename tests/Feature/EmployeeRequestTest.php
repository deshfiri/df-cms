<?php

namespace Tests\Feature;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestResolved;
use App\Notifications\RequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'manage requests', 'guard_name' => 'web']);
    }

    /** 'manage requests' is the only permission requests still take. */
    private function makeUser(?string $role = null): User
    {
        $user = User::factory()->create();
        if ($role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
            if ($role === 'Manager') {
                $user->givePermissionTo('manage requests');
            }
        }

        return $user;
    }

    // ── Who may use requests at all ──────────────────────────────────────

    public function test_anyone_signed_in_can_open_the_page_and_file_one(): void
    {
        // No role, no permissions: asking for something is part of having a login.
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('requests.index'))
            ->assertOk()
            ->assertSee('data-bs-target="#newRequestModal"', false);

        $this->actingAs($nobody)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->actingAs($nobody)
            ->postJson(route('requests.store'), ['subject' => 'Laptop', 'message' => 'Please'])
            ->assertOk();

        $this->assertDatabaseHas('employee_requests', ['subject' => 'Laptop', 'requested_by' => $nobody->id]);
    }

    public function test_the_sidebar_offers_requests_to_everyone(): void
    {
        $approver = tap(User::factory()->create())->givePermissionTo('manage requests');

        foreach ([User::factory()->create(), $this->makeUser('Accounts'), $approver->fresh()] as $user) {
            $this->actingAs($user)->get(route('my-work'))
                ->assertOk()
                ->assertSee(route('requests.index'), false);
        }
    }

    public function test_a_department_worker_gets_the_requests_menu_too(): void
    {
        // Stage workers used to be given a trimmed menu that dropped Requests.
        Permission::firstOrCreate(['name' => 'submit-stage', 'guard_name' => 'web']);
        $worker = tap(User::factory()->create())->givePermissionTo('submit-stage');

        $this->actingAs($worker->fresh())->get(route('my-work'))
            ->assertOk()
            ->assertSee(route('requests.index'), false)
            ->assertSee(route('my-work'), false);
    }

    public function test_withdrawing_your_own_pending_request_needs_nothing_extra(): void
    {
        $employee = User::factory()->create();
        $pending  = EmployeeRequest::create(['subject' => 'Still open', 'message' => 'msg', 'requested_by' => $employee->id]);

        $this->actingAs($employee)->deleteJson(route('requests.destroy', $pending))->assertOk();
        $this->assertSoftDeleted('employee_requests', ['id' => $pending->id]);
    }

    public function test_you_still_cannot_touch_someone_elses_request(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $theirs   = EmployeeRequest::create(['subject' => 'Private', 'message' => 'msg', 'requested_by' => $owner->id]);

        $this->actingAs($stranger)->deleteJson(route('requests.destroy', $theirs))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('requests.respond', $theirs), ['status' => 'Approved'])->assertForbidden();

        $list = $this->actingAs($stranger)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertFalse(collect($list->json('data'))->pluck('subject')->contains('Private'));
    }

    // ── Filing and responding ────────────────────────────────────────────

    public function test_any_authenticated_user_can_submit_a_request(): void
    {
        Notification::fake();
        $employee = $this->makeUser('Sales');

        $response = $this->actingAs($employee)->postJson(route('requests.store'), [
            'subject' => 'Need a new laptop',
            'message' => 'My current one is too slow.',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('employee_requests', [
            'subject'      => 'Need a new laptop',
            'requested_by' => $employee->id,
            'status'       => EmployeeRequest::STATUS_PENDING,
        ]);
    }

    public function test_a_non_manager_only_sees_their_own_requests_in_the_list(): void
    {
        $employeeA = $this->makeUser('Sales');
        $employeeB = $this->makeUser('Sales');

        EmployeeRequest::create(['subject' => 'From A', 'message' => 'msg', 'requested_by' => $employeeA->id]);
        EmployeeRequest::create(['subject' => 'From B', 'message' => 'msg', 'requested_by' => $employeeB->id]);

        $response = $this->actingAs($employeeA)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();

        $subjects = collect($response->json('data'))->pluck('subject');
        $this->assertTrue($subjects->contains('From A'));
        $this->assertFalse($subjects->contains('From B'));
    }

    public function test_a_manager_sees_all_requests_and_can_approve_one(): void
    {
        Notification::fake();
        $employee = $this->makeUser('Sales');
        $manager  = $this->makeUser('Manager');

        // Submitted through the endpoint (not Eloquent directly) so the
        // approver notification, which the service fires on create, actually runs.
        $this->actingAs($employee)->postJson(route('requests.store'), [
            'subject' => 'Budget ask',
            'message' => 'msg',
        ])->assertOk();
        $request = EmployeeRequest::where('subject', 'Budget ask')->firstOrFail();

        $listResponse = $this->actingAs($manager)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $listResponse->assertOk();
        $this->assertTrue(collect($listResponse->json('data'))->pluck('subject')->contains('Budget ask'));

        $respondResponse = $this->actingAs($manager)->postJson(route('requests.respond', $request), [
            'status' => 'Approved',
            'note'   => 'Go ahead.',
        ]);

        $respondResponse->assertOk()->assertJson(['success' => true]);
        $request->refresh();
        $this->assertSame(EmployeeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($manager->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame('Go ahead.', $request->response_note);

        Notification::assertSentTo($manager, RequestSubmitted::class);
        Notification::assertSentTo($employee, RequestResolved::class);
    }

    public function test_responding_twice_to_the_same_request_is_rejected(): void
    {
        $employee = $this->makeUser('Sales');
        $manager  = $this->makeUser('Manager');

        $request = EmployeeRequest::create([
            'subject' => 'Already handled', 'message' => 'msg', 'requested_by' => $employee->id,
            'status' => EmployeeRequest::STATUS_APPROVED, 'reviewed_by' => $manager->id, 'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($manager)->postJson(route('requests.respond', $request), ['status' => 'Rejected']);

        $response->assertStatus(422);
    }

    public function test_a_user_without_manage_requests_permission_cannot_respond(): void
    {
        $employee = $this->makeUser('Sales');
        $other    = $this->makeUser('Sales');

        $request = EmployeeRequest::create(['subject' => 'Something', 'message' => 'msg', 'requested_by' => $employee->id]);

        $response = $this->actingAs($other)->postJson(route('requests.respond', $request), ['status' => 'Approved']);

        $response->assertStatus(403);
    }

    public function test_a_requester_can_delete_their_own_pending_request_but_not_once_resolved(): void
    {
        $employee = $this->makeUser('Sales');

        $pending = EmployeeRequest::create(['subject' => 'Still open', 'message' => 'msg', 'requested_by' => $employee->id]);
        $resolved = EmployeeRequest::create([
            'subject' => 'Done', 'message' => 'msg', 'requested_by' => $employee->id,
            'status' => EmployeeRequest::STATUS_APPROVED,
        ]);

        $this->actingAs($employee)->deleteJson(route('requests.destroy', $pending))->assertOk();
        $this->assertSoftDeleted('employee_requests', ['id' => $pending->id]);

        $this->actingAs($employee)->deleteJson(route('requests.destroy', $resolved))->assertStatus(403);
    }
}
