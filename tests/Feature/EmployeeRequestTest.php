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

        foreach (['manage requests', 'view requests', 'create requests'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /** Roles in these tests hold the request seats a seeded install gives them. */
    private function makeUser(?string $role = null): User
    {
        $user = User::factory()->create();
        if ($role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
            $user->givePermissionTo($role === 'Manager' ? ['manage requests'] : ['view requests', 'create requests']);
        }

        return $user;
    }

    // ── Who may use requests at all ──────────────────────────────────────

    public function test_a_role_without_request_permissions_cannot_see_or_file_them(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        $this->actingAs($nobody)
            ->postJson(route('requests.store'), ['subject' => 'Laptop', 'message' => 'Please'])
            ->assertForbidden();

        $this->assertSame(0, EmployeeRequest::count());
    }

    public function test_view_only_lets_you_follow_requests_but_not_file_one(): void
    {
        $watcher = User::factory()->create();
        $watcher->givePermissionTo('view requests');

        $this->actingAs($watcher)->get(route('requests.index'))
            ->assertOk()
            ->assertDontSee('data-bs-target="#newRequestModal"', false);

        $this->actingAs($watcher)
            ->postJson(route('requests.store'), ['subject' => 'Laptop', 'message' => 'Please'])
            ->assertForbidden();
    }

    public function test_create_lets_you_file_and_offers_the_button(): void
    {
        $filer = $this->makeUser('Sales');

        $this->actingAs($filer)->get(route('requests.index'))
            ->assertOk()
            ->assertSee('data-bs-target="#newRequestModal"', false);
    }

    public function test_the_sidebar_only_offers_requests_to_those_who_may_use_them(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('requests.index'), false);

        $this->actingAs($this->makeUser('Accounts'))
            ->get(route('dashboard'))
            ->assertSee(route('requests.index'), false);
    }

    public function test_withdrawing_your_own_request_needs_the_right_to_make_one(): void
    {
        $employee = $this->makeUser('Sales');
        $pending  = EmployeeRequest::create(['subject' => 'Still open', 'message' => 'msg', 'requested_by' => $employee->id]);

        $employee->revokePermissionTo('create requests');

        $this->actingAs($employee->fresh())->deleteJson(route('requests.destroy', $pending))->assertForbidden();
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
