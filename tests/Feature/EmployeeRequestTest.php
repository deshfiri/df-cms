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
