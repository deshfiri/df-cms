<?php

namespace Tests\Feature;

use App\Models\EmployeeCapacity;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskReviewed;
use App\Services\TaskService;
use App\Services\WorkloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * True shared ownership: a task can be held by more than one person at once,
 * and any of them can act on it — not one "real" assignee plus watchers.
 */
class TaskMultiAssigneeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function worker(string $name = 'Worker'): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        $user->givePermissionTo('view tasks');

        return $user->fresh();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view tasks', 'manage tasks', 'manage all tasks']);

        return $user->fresh();
    }

    private function sharedTask(User $creator, array $assignees): Task
    {
        $this->actingAs($creator);

        return app(TaskService::class)->create([
            'title' => 'Shared brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => array_map(fn (User $u) => $u->id, $assignees),
        ]);
    }

    public function test_creating_a_task_with_several_assignees_notifies_every_one_of_them(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');

        $this->sharedTask($manager, [$a, $b]);

        Notification::assertSentTo($a, TaskAssigned::class);
        Notification::assertSentTo($b, TaskAssigned::class);
    }

    public function test_either_assignee_can_start_it_and_either_can_submit_it(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        // A starts it...
        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();

        // ...but B, who never started it themselves, can still hand it in —
        // it is the task's state that matters, not who moved it there.
        $this->actingAs($b)->postJson(route('tasks.submit', $task), ['note' => 'Done'])->assertOk();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_reviewing_notifies_every_assignee_except_the_reviewer(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();

        Notification::fake();
        $this->actingAs($manager)->postJson(route('tasks.review', $task), ['accept' => true])->assertOk();

        Notification::assertSentTo($a, TaskReviewed::class);
        Notification::assertSentTo($b, TaskReviewed::class);
        Notification::assertNothingSentTo($manager);
    }

    public function test_reassignment_logs_who_was_added_and_removed_and_notifies_only_the_newly_added(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $c       = $this->worker('C');
        $task    = $this->sharedTask($manager, [$a, $b]);

        Notification::fake();   // ignore creation notifications
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => $task->title, 'priority' => $task->priority, 'status' => $task->status, 'type' => $task->type,
            'assignee_ids' => [$a->id, $c->id],
        ])->assertOk();

        $reassigned = TaskActivity::where('task_id', $task->id)->where('event', 'reassigned')->sole();
        $this->assertSame(['added' => [$c->id], 'removed' => [$b->id]], $reassigned->meta);

        Notification::assertSentTo($c, TaskAssigned::class);
        Notification::assertNotSentTo($a, TaskAssigned::class);
        Notification::assertNotSentTo($b, TaskAssigned::class);

        $this->assertSame([$a->id, $c->id], $task->fresh()->assignees->pluck('id')->sort()->values()->all());
    }

    public function test_workload_counts_a_shared_task_in_full_against_every_assignee(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        EmployeeCapacity::create(['user_id' => $a->id, 'max_active_tasks' => 5]);
        EmployeeCapacity::create(['user_id' => $b->id, 'max_active_tasks' => 5]);

        $this->sharedTask($manager, [$a, $b]);

        $workload = app(WorkloadService::class);
        // Sharing a task does not dilute anyone's workload: both carry it in full.
        $this->assertSame(1, $workload->load($a)['active_tasks']);
        $this->assertSame(1, $workload->load($b)['active_tasks']);
    }

    public function test_the_sidebar_badge_counts_open_work_for_every_assignee(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $this->sharedTask($manager, [$a, $b]);

        $this->assertSame(1, Task::pendingCountsFor($a)['open']);
        $this->assertSame(1, Task::pendingCountsFor($b)['open']);
    }

    public function test_self_assignment_is_refused_only_for_a_newly_added_person(): void
    {
        $manager = $this->manager();
        $other   = $this->worker('Other');

        // Manager cannot add themselves as a brand-new assignee.
        $this->actingAs($manager)->postJson(route('tasks.store'), [
            'title' => 'Errand', 'priority' => 'Low', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$manager->id, $other->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');

        // But re-saving a task where they were already an assignee (e.g. an
        // oversight override) does not re-trigger the refusal.
        $task = $this->sharedTask($manager, [$other]);
        $task->assignees()->sync([$manager->id, $other->id]);   // simulate a prior override, bypassing the rule

        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Errand renamed', 'priority' => 'Low', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$manager->id, $other->id],
        ])->assertOk();
    }
}
