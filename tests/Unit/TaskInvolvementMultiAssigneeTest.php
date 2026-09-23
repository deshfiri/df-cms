<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\TaskInvolvementService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Generalizing TaskInvolvementService's single "primary holder" to every
 * current assignee at once — the mechanism that makes true shared ownership
 * (see TaskService) also fair in the performance engine: credit still
 * follows what each person actually did, it just now has more than one
 * potential holder to divide between.
 */
class TaskInvolvementMultiAssigneeTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->tasks = app(TaskService::class);
    }

    private function user(string $name): User
    {
        return tap(User::factory()->create(['is_active' => true, 'name' => $name]))
            ->givePermissionTo('view tasks')->fresh();
    }

    private function freshWithCredit(Task $task): Task
    {
        return $task->fresh(['assignees', 'involvements']);
    }

    public function test_a_task_with_no_recorded_activity_credits_every_current_assignee_in_full(): void
    {
        $anika  = $this->user('Anika');
        $bashir = $this->user('Bashir');

        // Imported/legacy-style: assignees set directly, never touching
        // TaskService, so no TaskInvolvement rows exist for it at all.
        $task = Task::create(['title' => 'Imported', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other']);
        $task->assignees()->sync([$anika->id, $bashir->id]);

        $shares = PerformanceCalculationService::workSharesOf($this->freshWithCredit($task));

        // Each is independent — a per-(user, task) number, never summed across
        // users — so both getting full credit does not double-count anything.
        $this->assertSame(1.0, $shares[$anika->id]);
        $this->assertSame(1.0, $shares[$bashir->id]);
    }

    public function test_two_assignees_who_did_equally_little_split_credit_evenly_not_by_headcount_bias(): void
    {
        $manager = $this->user('Manager');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');

        $this->actingAs($manager);
        $task = $this->tasks->create([
            'title' => 'Brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$anika->id, $bashir->id],
        ]);

        $task  = $this->freshWithCredit($task);
        $roles = $task->involvements->pluck('role', 'user_id');
        $this->assertSame(TaskInvolvementService::ROLE_PRIMARY, $roles[$anika->id]);
        $this->assertSame(TaskInvolvementService::ROLE_PRIMARY, $roles[$bashir->id]);

        $shares = PerformanceCalculationService::workSharesOf($task);

        // Both hold it, neither has done anything yet — holding points alone
        // are equal, so the split is even, not tilted toward either.
        $this->assertEqualsWithDelta(0.5, $shares[$anika->id], 0.0001);
        $this->assertEqualsWithDelta(0.5, $shares[$bashir->id], 0.0001);
    }

    public function test_credit_follows_actual_work_not_headcount_when_one_assignee_does_everything(): void
    {
        $manager = $this->user('Manager');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');

        $this->actingAs($manager);
        $task = $this->tasks->create([
            'title' => 'Brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$anika->id, $bashir->id],
        ]);

        // Anika does all the work; Bashir just sits on it as a co-assignee.
        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task->fresh(), $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);

        $shares = PerformanceCalculationService::workSharesOf($this->freshWithCredit($task));

        // Anika: 2 (started) + 4 (submitted) + 1 (holding) = 7.
        // Bashir: 0 + 1 (holding, still a current assignee) = 1. Total 8.
        $this->assertEqualsWithDelta(7 / 8, $shares[$anika->id], 0.0001);
        $this->assertEqualsWithDelta(1 / 8, $shares[$bashir->id], 0.0001);
        $this->assertGreaterThan($shares[$bashir->id], $shares[$anika->id]);
    }

    public function test_reassigning_replaces_who_is_primary_added_and_removed_are_both_tracked(): void
    {
        $manager = $this->user('Manager');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $chandra = $this->user('Chandra');

        $this->actingAs($manager);
        $task = $this->tasks->create([
            'title' => 'Brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$anika->id, $bashir->id],
        ]);

        // Swap Bashir for Chandra; Anika stays throughout.
        $this->tasks->update($task, ['assignee_ids' => [$anika->id, $chandra->id]]);

        $task = $this->freshWithCredit($task);
        $roles = $task->involvements->pluck('role', 'user_id');

        $this->assertSame(TaskInvolvementService::ROLE_PRIMARY, $roles[$anika->id]);
        $this->assertSame(TaskInvolvementService::ROLE_PRIMARY, $roles[$chandra->id]);
        $this->assertSame(TaskInvolvementService::ROLE_PASSED_THROUGH, $roles[$bashir->id]);
    }
}
