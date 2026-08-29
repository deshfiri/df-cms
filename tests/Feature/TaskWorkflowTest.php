<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskReviewed;
use App\Notifications\TaskSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The round trip a task makes: assigned → started → submitted → reviewed.
 *
 * The rule shaping it is that the two halves belong to different people. An
 * assignee owns the work and says when it is handed back; whoever asked for it
 * owns the verdict and says when it is done. Neither can do the other's part.
 */
class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /** Someone who can be given work but cannot administer tasks. */
    private function worker(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('view tasks');

        return $user->fresh();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view tasks', 'manage tasks']);

        return $user->fresh();
    }

    private function task(User $assignee, User $creator, string $status = 'Pending'): Task
    {
        return Task::create([
            'title'       => 'Write the onboarding doc',
            'priority'    => 'Medium',
            'status'      => $status,
            'type'        => 'Other',
            'assigned_to' => $assignee->id,
            'created_by'  => $creator->id,
        ]);
    }

    // ── Starting work ────────────────────────────────────────────────────

    /**
     * The gap this closes: editing a task needs 'manage tasks', so without a
     * narrower ability an ordinary assignee could not say they had started.
     */
    public function test_an_assignee_can_start_their_own_task_without_manage_permission(): void
    {
        $worker  = $this->worker();
        $manager = $this->manager();
        $task    = $this->task($worker, $manager);

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])
            ->assertOk();

        $this->assertSame('In Progress', $task->fresh()->status);
    }

    public function test_an_assignee_can_pause_and_resume(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager(), 'In Progress');

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'On Hold'])
            ->assertOk();
        $this->assertSame('On Hold', $task->fresh()->status);

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])
            ->assertOk();
        $this->assertSame('In Progress', $task->fresh()->status);
    }

    /** Starting work must not become a way to declare it finished. */
    public function test_an_assignee_cannot_mark_their_own_task_completed(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager(), 'In Progress');

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'Completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('In Progress', $task->fresh()->status);
    }

    public function test_an_assignee_cannot_cancel_their_own_task(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager(), 'In Progress');

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'Cancelled'])
            ->assertStatus(422);
    }

    public function test_somebody_elses_task_cannot_be_started(): void
    {
        $mine      = $this->worker();
        $theirs    = $this->worker();
        $task      = $this->task($theirs, $this->manager());

        $this->actingAs($mine)
            ->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])
            ->assertForbidden();

        $this->assertSame('Pending', $task->fresh()->status);
    }

    /** Once handed in, the assignee no longer moves it — it is the reviewer's. */
    public function test_a_submitted_task_can_no_longer_be_started(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager(), Task::STATUS_SUBMITTED);

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])
            ->assertForbidden();
    }

    public function test_starting_a_task_is_recorded_in_its_history(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager());

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])
            ->assertOk();

        $this->assertDatabaseHas('task_activities', [
            'task_id'     => $task->id,
            'action'      => 'Status Changed',
            'description' => 'Pending → In Progress',
        ]);
    }

    public function test_setting_the_status_it_already_has_changes_nothing(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager(), 'In Progress');

        $this->actingAs($worker)
            ->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])
            ->assertOk();

        // No activity row: a double-click should not litter the feed.
        $this->assertDatabaseMissing('task_activities', [
            'task_id' => $task->id,
            'action'  => 'Status Changed',
        ]);
    }

    // ── Submitting ───────────────────────────────────────────────────────

    public function test_submitting_notifies_whoever_assigned_the_task(): void
    {
        $worker  = $this->worker();
        $manager = $this->manager();
        $task    = $this->task($worker, $manager, 'In Progress');

        $this->actingAs($worker)
            ->postJson(route('tasks.submit', $task), ['note' => 'Draft is ready'])
            ->assertOk();

        $fresh = $task->fresh();
        $this->assertSame(Task::STATUS_SUBMITTED, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);

        Notification::assertSentTo($manager, TaskSubmitted::class);
    }

    public function test_only_the_assignee_may_submit(): void
    {
        $worker    = $this->worker();
        $bystander = $this->worker();
        $task      = $this->task($worker, $this->manager(), 'In Progress');

        $this->actingAs($bystander)
            ->postJson(route('tasks.submit', $task))
            ->assertForbidden();
    }

    // ── Reviewing ────────────────────────────────────────────────────────

    public function test_the_assigner_accepts_the_work_and_it_completes(): void
    {
        $worker  = $this->worker();
        $manager = $this->manager();
        $task    = $this->task($worker, $manager, Task::STATUS_SUBMITTED);

        $this->actingAs($manager)
            ->postJson(route('tasks.review', $task), ['accept' => true, 'note' => 'Looks good'])
            ->assertOk();

        $fresh = $task->fresh();
        $this->assertSame('Completed', $fresh->status);
        $this->assertNotNull($fresh->completion_date);

        Notification::assertSentTo($worker, TaskReviewed::class);
    }

    /** Sending it back reopens the work rather than closing it. */
    public function test_the_assigner_can_send_the_work_back(): void
    {
        $worker  = $this->worker();
        $manager = $this->manager();
        $task    = $this->task($worker, $manager, Task::STATUS_SUBMITTED);

        $this->actingAs($manager)
            ->postJson(route('tasks.review', $task), [
                'accept'          => false,
                'reason_category' => 'Employee Mistake',
                'note'            => 'Section 3 is missing',
            ])
            ->assertOk();

        $this->assertNotSame('Completed', $task->fresh()->status);
        $this->assertDatabaseHas('task_revisions', ['task_id' => $task->id]);

        Notification::assertSentTo($worker, TaskReviewed::class);
    }

    /** The verdict is not the assignee's to give, even on their own task. */
    public function test_the_assignee_cannot_review_their_own_submission(): void
    {
        $worker = $this->worker();
        $task   = $this->task($worker, $this->manager(), Task::STATUS_SUBMITTED);

        $this->actingAs($worker)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertForbidden();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_a_task_that_was_never_submitted_cannot_be_reviewed(): void
    {
        $manager = $this->manager();
        $task    = $this->task($this->worker(), $manager, 'In Progress');

        $this->actingAs($manager)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertForbidden();
    }

    /**
     * A manager can clear someone else's review queue, so work never gets stuck
     * behind a person who has left or is away.
     */
    public function test_a_manager_can_review_a_task_they_did_not_assign(): void
    {
        $worker       = $this->worker();
        $originalBoss = $this->manager();
        $otherManager = $this->manager();
        $task         = $this->task($worker, $originalBoss, Task::STATUS_SUBMITTED);

        $this->actingAs($otherManager)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertOk();

        $this->assertSame('Completed', $task->fresh()->status);
    }

    // ── Visibility ───────────────────────────────────────────────────────

    /**
     * A task is between the person who asked for it and the person doing it.
     * Holding 'view tasks' means you can use the module, not read everyone's
     * workload.
     */
    public function test_a_bystander_cannot_open_someone_elses_task(): void
    {
        $task = $this->task($this->worker(), $this->manager());

        $this->actingAs($this->worker())
            ->getJson(route('tasks.show', $task))
            ->assertForbidden();
    }

    public function test_the_assignee_can_open_their_task(): void
    {
        $worker = $this->worker();

        $this->actingAs($worker)
            ->getJson(route('tasks.show', $this->task($worker, $this->manager())))
            ->assertOk();
    }

    public function test_the_creator_can_open_a_task_they_delegated(): void
    {
        $creator = $this->worker();
        $task    = $this->task($this->worker(), $creator);

        $this->actingAs($creator)
            ->getJson(route('tasks.show', $task))
            ->assertOk();
    }

    /** Oversight: a manager still sees everything, and can clear a review queue. */
    public function test_a_manager_can_open_any_task(): void
    {
        $task = $this->task($this->worker(), $this->worker());

        $this->actingAs($this->manager())
            ->getJson(route('tasks.show', $task))
            ->assertOk();
    }

    /**
     * The listing must hide exactly what the policy refuses. A divergence would
     * mean a task absent from the list but reachable by editing the URL.
     */
    public function test_the_list_shows_only_tasks_you_are_party_to(): void
    {
        $me      = $this->worker();
        $manager = $this->manager();

        $mine      = $this->task($me, $manager);              // assigned to me
        $delegated = $this->task($this->worker(), $me);       // I asked for it
        $theirs    = $this->task($this->worker(), $manager);  // nothing to do with me

        $response = $this->actingAs($me)
            // The controller serves the DataTable only to an XHR; without this
            // header it renders the page instead.
            ->getJson(route('tasks.index') . '?draw=1&start=0&length=50', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($delegated->id, $ids);
        $this->assertNotContains($theirs->id, $ids, "Another person's task leaked into the list.");
    }

    public function test_a_manager_sees_every_task_in_the_list(): void
    {
        $theirs = $this->task($this->worker(), $this->worker());

        $response = $this->actingAs($this->manager())
            // The controller serves the DataTable only to an XHR; without this
            // header it renders the page instead.
            ->getJson(route('tasks.index') . '?draw=1&start=0&length=50', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertContains($theirs->id, collect($response->json('data'))->pluck('id')->all());
    }

    /** Comments and attachments hang off the same authorization. */
    public function test_a_bystander_cannot_comment_on_someone_elses_task(): void
    {
        $task = $this->task($this->worker(), $this->manager());

        $this->actingAs($this->worker())
            ->postJson(route('tasks.comments.store', $task), ['comment' => 'nosy'])
            ->assertForbidden();
    }

    // ── Due dates ────────────────────────────────────────────────────────

    /**
     * A task due today is not late.
     *
     * due_date casts to a Carbon at midnight, so isPast() on it was true from
     * 00:00 — which flagged same-day work as overdue the moment the day began,
     * and disagreed with the query scope used everywhere else.
     */
    public function test_a_task_due_today_is_not_overdue(): void
    {
        $task = $this->task($this->worker(), $this->manager());
        $task->forceFill(['due_date' => today()])->save();

        $this->assertFalse($task->fresh()->is_overdue);

        // The attribute and the scope must give the same answer.
        $this->assertSame(0, Task::overdue()->count());
    }

    public function test_a_task_due_yesterday_is_overdue(): void
    {
        $task = $this->task($this->worker(), $this->manager());
        $task->forceFill(['due_date' => today()->subDay()])->save();

        $this->assertTrue($task->fresh()->is_overdue);
        $this->assertSame(1, Task::overdue()->count());
    }

    public function test_a_task_can_start_and_finish_on_the_same_day(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->postJson(route('tasks.store'), [
            'title'      => 'Same day job',
            'priority'   => 'High',
            'status'     => 'Pending',
            'type'       => 'Other',
            'start_date' => today()->toDateString(),
            'due_date'   => today()->toDateString(),
        ])->assertOk();

        $task = Task::firstOrFail();

        $this->assertSame(today()->toDateString(), $task->due_date->toDateString());
        $this->assertFalse($task->is_overdue);
    }

    public function test_a_task_needs_no_dates_at_all(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->postJson(route('tasks.store'), [
            'title'    => 'Whenever',
            'priority' => 'Low',
            'status'   => 'Pending',
            'type'     => 'Other',
        ])->assertOk();

        $task = Task::firstOrFail();

        $this->assertNull($task->due_date);
        $this->assertFalse($task->is_overdue);
    }

    // ── The whole round trip ─────────────────────────────────────────────

    public function test_the_full_cycle_from_assignment_to_completion(): void
    {
        $worker  = $this->worker();
        $manager = $this->manager();
        $task    = $this->task($worker, $manager);

        $this->actingAs($worker)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->assertSame('In Progress', $task->fresh()->status);

        $this->actingAs($worker)->postJson(route('tasks.submit', $task), ['note' => 'Done'])->assertOk();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
        Notification::assertSentTo($manager, TaskSubmitted::class);

        $this->actingAs($manager)->postJson(route('tasks.review', $task), ['accept' => true])->assertOk();
        $this->assertSame('Completed', $task->fresh()->status);
        Notification::assertSentTo($worker, TaskReviewed::class);
    }
}
