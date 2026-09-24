<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task::submitBlocker() in isolation — the one rule shared by the policy,
 * the service (re-checked under a lock) and the page (the disabled button's
 * tooltip). $user is who's asking: on a shared task, "already submitted" is
 * a per-assignee fact now, not a task-wide one.
 */
class TaskSubmitBlockerTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function task(array $assignees, string $status, array $submitted = []): Task
    {
        $task = Task::create(['title' => 'x', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other']);
        $task->assignees()->sync(collect($assignees)->pluck('id'));

        foreach ($submitted as $u) {
            $task->assignees()->updateExistingPivot($u->id, ['submitted_at' => now()]);
        }

        return $task->fresh();
    }

    public function test_a_task_not_yet_started_blocks_with_a_start_work_reason(): void
    {
        $anika = $this->user();
        $task  = $this->task([$anika], 'Pending');

        $this->assertSame('Start work on this task before submitting it.', $task->submitBlocker($anika));
    }

    public function test_a_task_in_progress_is_not_blocked(): void
    {
        $anika = $this->user();
        $task  = $this->task([$anika], 'In Progress');

        $this->assertNull($task->submitBlocker($anika));
    }

    public function test_a_fully_submitted_task_is_blocked_for_everyone(): void
    {
        $anika = $this->user();
        $task  = $this->task([$anika], Task::STATUS_SUBMITTED, submitted: [$anika]);

        $this->assertSame(
            'This task has already been submitted and is waiting for review.',
            $task->submitBlocker($anika),
        );
    }

    /** The rule this whole feature exists for. */
    public function test_an_assignee_who_already_submitted_their_part_is_blocked_but_the_other_is_not(): void
    {
        $anika  = $this->user();
        $bashir = $this->user();
        $task   = $this->task([$anika, $bashir], Task::STATUS_PARTIALLY_SUBMITTED, submitted: [$anika]);

        $this->assertSame(
            'You already submitted your part of this task — waiting on the other assignee(s) to submit theirs.',
            $task->submitBlocker($anika),
        );
        $this->assertNull($task->submitBlocker($bashir), 'Bashir has not submitted yet, so nothing blocks him');
    }

    public function test_a_completed_or_cancelled_task_is_blocked(): void
    {
        $anika = $this->user();

        foreach (['Completed', 'Cancelled'] as $status) {
            $task = $this->task([$anika], $status);
            $this->assertSame(
                "This task is {$status} and can no longer be submitted.",
                $task->submitBlocker($anika),
            );
        }
    }

    public function test_defaults_to_the_signed_in_user_when_none_is_given(): void
    {
        $anika = $this->user();
        $this->actingAs($anika);
        $task = $this->task([$anika], 'Pending');

        $this->assertSame($task->submitBlocker(), $task->submitBlocker($anika));
    }
}
