<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskLifecycleBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * When a task was created, started, is due, and was finished — as moments, not
 * just days — kept consistent however the task is saved.
 */
class TaskLifecycleTimestampsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Carbon::setTestNow('2026-09-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo($permissions ?: ['view tasks'])->fresh();
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['title' => 'Brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other'];
    }

    // ── Deadline ─────────────────────────────────────────────────────────

    public function test_a_date_only_deadline_means_the_end_of_that_day(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');

        $this->actingAs($manager)->postJson(route('tasks.store'), $this->payload(['due_date' => '2026-09-17']))->assertOk();

        $task = Task::sole();
        $this->assertSame('2026-09-17 23:59:59', $task->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-17', $task->due_date->toDateString());
        $this->assertFalse($task->is_overdue, 'Due today is not late.');
        $this->assertFalse($task->dueHasTime());
    }

    public function test_an_exact_deadline_is_stored_as_that_moment_whatever_the_senders_zone(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');

        // 18:00 in Dhaka (UTC+6) is 12:00 UTC.
        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload(['due_date' => '2026-09-17', 'due_at' => '2026-09-17T18:00:00+06:00']))
            ->assertOk();

        $task = Task::sole();
        $this->assertSame('2026-09-17 12:00:00', $task->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-17', $task->due_date->toDateString());
        $this->assertTrue($task->dueHasTime());
    }

    public function test_an_exact_deadline_is_late_the_moment_it_passes(): void
    {
        $task = Task::create($this->payload(['due_at' => '2026-09-17 12:00:00']));

        $this->assertFalse($task->fresh()->is_overdue);
        $this->assertSame(0, Task::overdue()->count());

        Carbon::setTestNow('2026-09-17 12:00:01');

        $this->assertTrue($task->fresh()->is_overdue);
        $this->assertSame(1, Task::overdue()->count());
    }

    public function test_sending_only_a_date_clears_a_previous_time(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');
        $task = Task::create($this->payload(['due_at' => '2026-09-18 09:30:00', 'created_by' => $manager->id]));

        $this->actingAs($manager)
            ->putJson(route('tasks.update', $task), $this->payload(['due_date' => '2026-09-18']))
            ->assertOk();

        $this->assertSame('2026-09-18 23:59:59', $task->fresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_the_day_follows_the_moment_and_the_moment_follows_the_day(): void
    {
        $task = Task::create($this->payload(['due_date' => '2026-09-20']));
        $this->assertSame('2026-09-20 23:59:59', $task->due_at->format('Y-m-d H:i:s'));

        $task->update(['due_at' => '2026-09-25 08:00:00']);
        $this->assertSame('2026-09-25', $task->fresh()->due_date->toDateString());

        $task->update(['due_date' => null]);
        $this->assertNull($task->fresh()->due_at);
    }

    // ── Start and completion ─────────────────────────────────────────────

    public function test_starting_work_records_when_it_first_began(): void
    {
        $assignee = $this->user();
        $task = Task::create($this->payload(['assigned_to' => $assignee->id]));
        $service = app(TaskService::class);
        $this->actingAs($assignee);

        $service->changeWorkingStatus($task, $assignee, 'In Progress');
        $this->assertSame('2026-09-17 10:00:00', $task->fresh()->started_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-09-17 11:00:00');
        $service->changeWorkingStatus($task->fresh(), $assignee, 'On Hold');
        Carbon::setTestNow('2026-09-17 12:00:00');
        $service->changeWorkingStatus($task->fresh(), $assignee, 'In Progress');

        $this->assertSame('2026-09-17 10:00:00', $task->fresh()->started_at->format('Y-m-d H:i:s'), 'Resuming does not move the start.');
    }

    public function test_completion_is_stamped_and_cleared_if_the_task_is_reopened(): void
    {
        $manager  = $this->user('view tasks', 'manage tasks');
        $assignee = $this->user();
        $task = Task::create($this->payload(['assigned_to' => $assignee->id, 'created_by' => $manager->id]));
        $service = app(TaskService::class);

        $this->actingAs($assignee);
        $service->submitForReview($task, $assignee);

        Carbon::setTestNow('2026-09-17 15:30:00');
        $this->actingAs($manager);
        $service->review($task->fresh(), $manager, true);

        $task->refresh();
        $this->assertSame('Completed', $task->status);
        $this->assertSame('2026-09-17 15:30:00', $task->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-17', $task->completion_date->toDateString());

        $service->requestRevision($task, ['reason_category' => 'Employee Mistake']);

        $task->refresh();
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completion_date);
    }

    // ── What the counter is told ─────────────────────────────────────────

    public function test_the_task_reports_its_timer_from_server_time(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');
        $task = Task::create($this->payload([
            'created_by' => $manager->id, 'estimated_hours' => 2,
            'due_at' => '2026-09-17 18:00:00', 'status' => 'In Progress',
        ]));

        Carbon::setTestNow('2026-09-17 10:45:00');

        $this->actingAs($manager)->getJson(route('tasks.show', $task))
            ->assertOk()
            ->assertJsonPath('timer.state', 'running')
            ->assertJsonPath('timer.estimated_seconds', 7200)
            ->assertJsonPath('timer.elapsed_seconds', 2700)
            ->assertJsonPath('timer.due_has_time', true)
            ->assertJsonPath('timer.server_now', now()->toIso8601String());
    }

    public function test_timer_states(): void
    {
        $this->assertSame('not_started', Task::create($this->payload())->timer()['state']);
        $this->assertSame('overdue', Task::create($this->payload(['due_at' => '2026-09-16 09:00:00']))->timer()['state']);
        $this->assertSame('running', Task::create($this->payload(['status' => 'In Progress']))->timer()['state']);

        $paused = Task::create($this->payload(['status' => 'In Progress']));
        $paused->update(['status' => 'On Hold']);
        $this->assertSame('paused', $paused->fresh()->timer()['state']);

        $this->assertSame('submitted', Task::create($this->payload(['status' => 'Submitted']))->timer()['state']);
        $this->assertSame('completed', Task::create($this->payload(['status' => 'Completed']))->timer()['state']);
    }

    // ── Existing tasks ───────────────────────────────────────────────────

    public function test_existing_tasks_are_backfilled_from_their_own_history(): void
    {
        $user = $this->user();

        $id = DB::table('tasks')->insertGetId([
            'title' => 'Old one', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-08-01', 'completion_date' => '2026-08-03',
            'created_at' => '2026-07-30 09:00:00', 'updated_at' => '2026-08-05 09:00:00',
        ]);
        TaskActivity::insert([
            ['task_id' => $id, 'user_id' => $user->id, 'action' => 'Status Changed', 'description' => 'Pending → In Progress', 'created_at' => '2026-07-31 08:15:00', 'updated_at' => '2026-07-31 08:15:00'],
            ['task_id' => $id, 'user_id' => $user->id, 'action' => 'Approved', 'description' => 'Submission accepted', 'created_at' => '2026-08-03 16:40:00', 'updated_at' => '2026-08-03 16:40:00'],
        ]);

        $untracked = DB::table('tasks')->insertGetId([
            'title' => 'No history', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TaskLifecycleBackfill::run();

        $task = Task::find($id);
        $this->assertSame('2026-08-01 23:59:59', $task->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-31 08:15:00', $task->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 16:40:00', $task->completed_at->format('Y-m-d H:i:s'));

        $blank = Task::find($untracked);
        $this->assertNull($blank->started_at, 'No history means no guess.');
        $this->assertNull($blank->due_at);
    }
}
