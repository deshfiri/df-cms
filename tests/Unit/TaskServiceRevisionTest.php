<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskRevisionRequested;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TaskService::requestRevision() in isolation — no HTTP layer, no policy
 * check. Covers the status-transition rule (only a submitted/completed task
 * gets reopened) and who gets notified, both in isolation from
 * TaskPolicyRevisionTest (the authorization gate) and
 * TaskGivingQualityPerformanceTest (the full HTTP round trip).
 */
class TaskServiceRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function task(User $creator, User $assignee, string $status): Task
    {
        $task = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'created_by' => $creator->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    public function test_it_does_not_touch_the_status_of_work_that_was_never_submitted(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        $task     = $this->task($creator, $assignee, 'Pending');
        Auth::login($assignee);

        app(TaskService::class)->requestRevision($task, ['reason_category' => 'Task Giver Mistake']);

        $this->assertSame('Pending', $task->fresh()->status);
    }

    public function test_it_reopens_submitted_or_completed_work_to_in_progress(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        $task     = $this->task($creator, $assignee, 'Submitted');
        Auth::login($creator);

        app(TaskService::class)->requestRevision($task, ['reason_category' => 'Employee Mistake']);

        $this->assertSame('In Progress', $task->fresh()->status);
    }

    public function test_the_creator_is_notified_when_the_assignee_requests_the_revision(): void
    {
        Notification::fake();
        $creator  = $this->user();
        $assignee = $this->user();
        $task     = $this->task($creator, $assignee, 'Pending');
        Auth::login($assignee);

        app(TaskService::class)->requestRevision($task, [
            'reason_category' => 'Task Giver Mistake', 'note' => 'Missing the reference file.',
        ]);

        Notification::assertSentTo($creator, TaskRevisionRequested::class,
            fn (TaskRevisionRequested $n) => $n->toDatabase($creator)['message'] === "{$assignee->name} sent \"Task\" back (Task Giver Mistake) — Missing the reference file.");
    }

    public function test_the_creator_is_not_notified_of_their_own_revision_request(): void
    {
        Notification::fake();
        $creator  = $this->user();
        $assignee = $this->user();
        $task     = $this->task($creator, $assignee, 'Submitted');
        Auth::login($creator);

        app(TaskService::class)->requestRevision($task, ['reason_category' => 'Employee Mistake']);

        Notification::assertNothingSentTo($creator);
    }

    public function test_nobody_is_notified_when_the_task_has_no_creator(): void
    {
        Notification::fake();
        $assignee = $this->user();
        $task = Task::create([
            'title' => 'Orphan task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
        ]);
        $task->assignees()->sync([$assignee->id]);
        Auth::login($assignee);

        app(TaskService::class)->requestRevision($task, ['reason_category' => 'Task Giver Mistake']);

        Notification::assertNothingSent();
    }
}
