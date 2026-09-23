<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TaskPolicy::requestRevision() in isolation, calling the policy method
 * directly rather than through a route — the full round trip (auth
 * middleware, controller, validation) is covered separately in
 * TaskWorkflowTest and TaskVisibilityTest.
 */
class TaskPolicyRevisionTest extends TestCase
{
    use RefreshDatabase;

    private TaskPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->policy = app(TaskPolicy::class);
    }

    private function worker(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('view tasks');

        return $user->fresh();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view tasks', 'manage tasks', 'manage all tasks']);

        return $user->fresh();
    }

    private function task(User $assignee, User $creator, string $status): Task
    {
        $task = Task::create([
            'title' => 'Write the onboarding doc', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'created_by' => $creator->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    public function test_oversight_may_request_a_revision_regardless_of_status(): void
    {
        $manager = $this->manager();
        $task    = $this->task($this->worker(), $this->worker(), 'Pending');

        $this->assertTrue($this->policy->requestRevision($manager, $task));
    }

    public function test_the_creator_with_manage_tasks_may_request_a_revision_regardless_of_status(): void
    {
        $creator = $this->manager();
        $task    = $this->task($this->worker(), $creator, 'In Progress');

        $this->assertTrue($this->policy->requestRevision($creator, $task));
    }

    public function test_the_creator_without_manage_tasks_may_not(): void
    {
        $creator = $this->worker();
        $task    = $this->task($this->worker(), $creator, Task::STATUS_SUBMITTED);

        $this->assertFalse($this->policy->requestRevision($creator, $task));
    }

    #[DataProvider('handedInStatuses')]
    public function test_the_assignee_may_request_a_revision_on_their_own_handed_in_work(string $status): void
    {
        $assignee = $this->worker();
        $task     = $this->task($assignee, $this->manager(), $status);

        $this->assertTrue($this->policy->requestRevision($assignee, $task));
    }

    public static function handedInStatuses(): array
    {
        return [
            'submitted, awaiting review' => [Task::STATUS_SUBMITTED],
            'completed'                  => ['Completed'],
        ];
    }

    #[DataProvider('notYetHandedInStatuses')]
    public function test_the_assignee_may_not_request_a_revision_before_handing_it_in(string $status): void
    {
        $assignee = $this->worker();
        $task     = $this->task($assignee, $this->manager(), $status);

        $this->assertFalse($this->policy->requestRevision($assignee, $task));
    }

    public static function notYetHandedInStatuses(): array
    {
        return [
            'pending'     => ['Pending'],
            'in progress' => ['In Progress'],
            'on hold'     => ['On Hold'],
        ];
    }

    public function test_a_bystander_may_never_request_a_revision(): void
    {
        $bystander = $this->worker();
        $task      = $this->task($this->worker(), $this->manager(), Task::STATUS_SUBMITTED);

        $this->assertFalse($this->policy->requestRevision($bystander, $task));
    }
}
