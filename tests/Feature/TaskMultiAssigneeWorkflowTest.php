<?php

namespace Tests\Feature;

use App\Models\EmployeeCapacity;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskPartiallySubmitted;
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

    /**
     * Neither assignee needs to have started it themselves to submit their
     * own part — starting and submitting are still each an individual act,
     * just no longer a single act that finishes the whole task alone. See
     * test_the_task_only_reaches_submitted_once_every_assignee_has() for
     * the two-submission requirement this replaced.
     */
    public function test_either_assignee_can_start_it_and_either_can_submit_their_own_part(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        // A starts it...
        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();

        // ...but B, who never started it themselves, can still hand in their
        // own part — only B is left to submit now, so this completes it.
        $this->actingAs($b)->postJson(route('tasks.submit', $task), ['note' => 'Done'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_the_task_only_reaches_submitted_once_every_assignee_has(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();

        // A alone is not the whole task — the reviewer cannot act on it yet.
        $this->assertSame(Task::STATUS_PARTIALLY_SUBMITTED, $task->fresh()->status);
        $this->actingAs($manager)->postJson(route('tasks.review', $task), ['accept' => true])->assertForbidden();

        // A cannot submit their own part twice while waiting on B.
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertStatus(422);

        $this->actingAs($b)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_submitting_notifies_only_whoever_has_not_submitted_yet(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $c       = $this->worker('C');
        $task    = $this->sharedTask($manager, [$a, $b, $c]);

        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        Notification::fake();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();

        Notification::assertSentTo($b, TaskPartiallySubmitted::class);
        Notification::assertSentTo($c, TaskPartiallySubmitted::class);
        Notification::assertNotSentTo($a, TaskPartiallySubmitted::class);
        // The reviewer hears nothing yet — the task is not ready for them.
        Notification::assertNothingSentTo($manager);

        Notification::fake();
        $this->actingAs($b)->postJson(route('tasks.submit', $task), [])->assertOk();
        // Only C is left, so only C gets nudged again.
        Notification::assertSentTo($c, TaskPartiallySubmitted::class);
        Notification::assertNotSentTo($a, TaskPartiallySubmitted::class);
    }

    public function test_sending_it_back_resets_everyones_submission_and_reopens_it(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(Task::STATUS_PARTIALLY_SUBMITTED, $task->fresh()->status);

        // A spots the problem and sends it back before B ever submits theirs.
        $this->actingAs($a)->postJson(route('tasks.revisions.store', $task), [
            'reason_category' => 'Task Giver Mistake', 'note' => 'Missing the brand colours.',
        ])->assertOk();

        $this->assertSame('In Progress', $task->fresh()->status);

        // A's own already-submitted part reset too — A submits again once
        // the brief is fixed, same as B, who never had submitted at all.
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(Task::STATUS_PARTIALLY_SUBMITTED, $task->fresh()->status, 'B still has not submitted their part');

        $this->actingAs($b)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_removing_an_assignee_who_already_submitted_does_not_hold_up_the_rest(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(Task::STATUS_PARTIALLY_SUBMITTED, $task->fresh()->status);

        // A is taken off the task entirely — B is now the only assignee.
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => $task->title, 'priority' => $task->priority, 'status' => $task->fresh()->status, 'type' => $task->type,
            'assignee_ids' => [$b->id],
        ])->assertOk();

        $this->actingAs($b)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status, 'B alone is now the whole task');
    }

    public function test_reviewing_notifies_every_assignee_except_the_reviewer(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        $task    = $this->sharedTask($manager, [$a, $b]);

        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->actingAs($b)->postJson(route('tasks.submit', $task), [])->assertOk();

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

    public function test_workload_still_counts_a_partially_submitted_task_for_the_assignee_still_owing_work(): void
    {
        $manager = $this->manager();
        $a       = $this->worker('A');
        $b       = $this->worker('B');
        EmployeeCapacity::create(['user_id' => $a->id, 'max_active_tasks' => 5]);
        EmployeeCapacity::create(['user_id' => $b->id, 'max_active_tasks' => 5]);

        $task = $this->sharedTask($manager, [$a, $b]);
        $this->actingAs($a)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($a)->postJson(route('tasks.submit', $task), [])->assertOk();

        $this->assertSame(Task::STATUS_PARTIALLY_SUBMITTED, $task->fresh()->status);

        $workload = app(WorkloadService::class);
        // B still owes their part — the task must not vanish from either load.
        $this->assertSame(1, $workload->load($a)['active_tasks']);
        $this->assertSame(1, $workload->load($b)['active_tasks']);
    }

    public function test_the_workload_overview_and_page_count_a_shared_task_for_every_assignee(): void
    {
        // Regression guard: overview() (and suggestAssignee()) fetch active
        // tasks via a raw task_user join, not Eloquent — unlike load() above.
        // buildLoad() must not assume it's always handed a Task model.
        Permission::firstOrCreate(['name' => 'view performance', 'guard_name' => 'web']);
        $manager = $this->manager();
        $manager->givePermissionTo('view performance');
        $a = $this->worker('A');
        $b = $this->worker('B');
        EmployeeCapacity::create(['user_id' => $a->id, 'max_active_tasks' => 5]);
        EmployeeCapacity::create(['user_id' => $b->id, 'max_active_tasks' => 5]);

        $this->sharedTask($manager, [$a, $b]);

        $rows = app(WorkloadService::class)->overview();
        $this->assertSame(1, $rows->firstWhere('user.id', $a->id)['active_tasks']);
        $this->assertSame(1, $rows->firstWhere('user.id', $b->id)['active_tasks']);

        $this->actingAs($manager)->get(route('performance.workload'))->assertOk();
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
