<?php

namespace Tests\Unit;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Policies\EmployeeRequestPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * EmployeeRequestPolicy in isolation, calling the policy directly rather
 * than through a route — the full round trip (auth middleware, controller,
 * validation) is covered separately in EmployeeRequestTest.
 *
 * The rule this exists to pin down: only the requester and whoever a request
 * was actually sent to (EmployeeRequest::recipients) may see or act on it.
 * "manage requests" decides nothing here any more.
 */
class EmployeeRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeRequestPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(EmployeeRequestPolicy::class);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function request(User $requester, array $recipients, string $status = EmployeeRequest::STATUS_PENDING): EmployeeRequest
    {
        $request = EmployeeRequest::create([
            'subject' => 'x', 'message' => 'x', 'requested_by' => $requester->id, 'status' => $status,
        ]);
        $request->recipients()->sync(collect($recipients)->pluck('id'));

        return $request->fresh(['recipients']);
    }

    public function test_viewany_and_create_are_open_to_everyone(): void
    {
        $anyone = $this->user();

        $this->assertTrue($this->policy->viewAny($anyone));
        $this->assertTrue($this->policy->create($anyone));
    }

    public function test_the_requester_may_view_their_own_request(): void
    {
        $requester = $this->user();
        $request   = $this->request($requester, [$this->user()]);

        $this->assertTrue($this->policy->view($requester, $request));
    }

    public function test_a_named_recipient_may_view_it(): void
    {
        $recipient = $this->user();
        $request   = $this->request($this->user(), [$recipient]);

        $this->assertTrue($this->policy->view($recipient, $request));
    }

    public function test_a_bystander_may_not_view_it(): void
    {
        $bystander = $this->user();
        $request   = $this->request($this->user(), [$this->user()]);

        $this->assertFalse($this->policy->view($bystander, $request));
    }

    public function test_holding_manage_requests_alone_grants_no_view_access(): void
    {
        Permission::firstOrCreate(['name' => 'manage requests', 'guard_name' => 'web']);
        $manager = tap($this->user())->givePermissionTo('manage requests')->fresh();
        $request = $this->request($this->user(), [$this->user()]);

        $this->assertFalse($this->policy->view($manager, $request));
        $this->assertFalse($this->policy->respond($manager, $request));
    }

    public function test_only_a_named_recipient_may_respond(): void
    {
        $requester = $this->user();
        $recipient = $this->user();
        $bystander = $this->user();
        $request   = $this->request($requester, [$recipient]);

        $this->assertTrue($this->policy->respond($recipient, $request));
        $this->assertFalse($this->policy->respond($bystander, $request));
        // Filing it isn't itself a way in — being named a recipient is.
        $this->assertFalse($this->policy->respond($requester, $request));
    }

    public function test_only_the_requester_may_delete_and_only_while_pending(): void
    {
        $requester = $this->user();
        $recipient = $this->user();
        $pending   = $this->request($requester, [$recipient]);
        $resolved  = $this->request($requester, [$recipient], EmployeeRequest::STATUS_APPROVED);

        $this->assertTrue($this->policy->delete($requester, $pending));
        $this->assertFalse($this->policy->delete($recipient, $pending));
        $this->assertFalse($this->policy->delete($requester, $resolved));
    }

    /**
     * The exact query shape DashboardController uses for its "Pending
     * Requests" widget — checked directly rather than by loading the full
     * dashboard page, which pulls in unrelated MySQL-only raw SQL elsewhere
     * in that same controller method and can't run under the test suite's
     * SQLite driver.
     */
    public function test_the_pending_for_a_recipient_query_shape_is_correctly_scoped(): void
    {
        $recipient = $this->user();
        $onlooker  = $this->user();
        $forThem   = $this->request($this->user(), [$recipient]);
        $notForThem = $this->request($this->user(), [$onlooker]);
        $resolved  = $this->request($this->user(), [$recipient], EmployeeRequest::STATUS_APPROVED);

        $visible = EmployeeRequest::query()
            ->pending()
            ->whereHas('recipients', fn ($q) => $q->where('users.id', $recipient->id))
            ->pluck('id');

        $this->assertTrue($visible->contains($forThem->id));
        $this->assertFalse($visible->contains($notForThem->id));
        $this->assertFalse($visible->contains($resolved->id), 'a resolved request is not pending any more');
    }
}
