<?php

namespace Tests\Feature;

use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\TaskRevision;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The full story this feature exists for: User B gets a task from User A,
 * finds it's missing information, sends it back citing "Task Giver
 * Mistake" — User A's performance is affected, User B's is not — User A
 * fixes the brief and reassigns it, and User B can act on it again.
 */
class TaskGivingQualityPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        foreach (['view tasks', 'manage tasks', 'view performance', 'manage performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view performance', 'manage performance']);
    }

    private function worker(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view tasks', 'manage tasks']);

        return $user->fresh();
    }

    public function test_the_full_story_giver_mistake_reopens_it_and_reassigning_hands_it_back(): void
    {
        Notification::fake();
        $userA = $this->worker(); // task giver
        $userB = $this->worker(); // assignee

        $this->actingAs($userA);
        $task = app(TaskService::class)->create([
            'title' => 'Design the landing page', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$userB->id], 'due_date' => '2026-09-25',
        ]);

        // B starts and submits the (incomplete) brief.
        $this->actingAs($userB)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($userB)->postJson(route('tasks.submit', $task), [])->assertOk();

        // B notices information is missing and sends it back to A.
        $this->actingAs($userB)->postJson(route('tasks.revisions.store', $task), [
            'reason_category' => 'Task Giver Mistake',
            'note' => 'Which brand colours should this use?',
        ])->assertOk();

        $task->refresh();
        $this->assertSame('In Progress', $task->status, 'sending it back reopens it for rework');
        $this->assertDatabaseHas('task_revisions', [
            'task_id' => $task->id, 'requested_by' => $userB->id, 'reason_category' => 'Task Giver Mistake',
        ]);

        // A's performance reflects it; B's quality KPI is untouched.
        $calc = app(PerformanceCalculationService::class);
        $giving = $calc->taskGivingQuality($userA, self::PERIOD);
        $this->assertSame(1, $giving['total_given']);
        $this->assertSame(1, $giving['flawed']);
        $this->assertSame(0.0, $giving['pct']);

        $bsRevision = $calc->revisionRate($userB, self::PERIOD);
        $this->assertSame(0, $bsRevision['requiring_revision'], "B's own quality KPI must not carry A's mistake");

        // A fixes the brief and re-sends it to B.
        $this->actingAs($userA)->putJson(route('tasks.update', $task), [
            'title' => 'Design the landing page', 'priority' => 'Medium', 'status' => $task->status, 'type' => 'Other',
            'description' => 'Use the navy/orange brand palette.', 'assignee_ids' => [$userB->id],
        ])->assertOk();

        // B can act on it again — the assignment survived the round trip.
        $this->assertTrue($task->fresh()->assignees->contains($userB->id));
        $this->actingAs($userB)->postJson(route('tasks.submit', $task), [])->assertOk();
        $this->assertSame(\App\Models\Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_an_employee_mistake_revision_does_not_affect_the_giver(): void
    {
        $userA = $this->worker();
        $userB = $this->worker();

        $this->actingAs($userA);
        $task = app(TaskService::class)->create([
            'title' => 'x', 'priority' => 'Medium', 'status' => 'Submitted', 'type' => 'Other',
            'assignee_ids' => [$userB->id], 'due_date' => '2026-09-25',
        ]);
        TaskRevision::create([
            'task_id' => $task->id, 'requested_by' => $userA->id,
            'reason_category' => 'Employee Mistake', 'previous_status' => 'Submitted',
        ]);

        // A did give out a task this period — it's just not counted as flawed,
        // since this revision wasn't A's fault.
        $giving = app(PerformanceCalculationService::class)->taskGivingQuality($userA, self::PERIOD);
        $this->assertSame(1, $giving['total_given']);
        $this->assertSame(0, $giving['flawed']);
        $this->assertSame(100.0, $giving['pct']);
    }

    // ── Configuration surface ────────────────────────────────────────────

    public function test_saving_global_weights_requires_a_task_giving_value(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 16, 'on_time_weight' => 16, 'revision_weight' => 11,
            'sales_weight' => 10, 'satisfaction_weight' => 10, 'client_care_weight' => 10,
            'daily_target_weight' => 8, 'output_volume_weight' => 9,
            // task_giving_weight omitted
        ])->assertStatus(422)->assertJsonValidationErrors('task_giving_weight');
    }

    public function test_weights_including_task_giving_must_total_100(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 16, 'on_time_weight' => 16, 'revision_weight' => 11,
            'sales_weight' => 10, 'satisfaction_weight' => 10, 'client_care_weight' => 10,
            'daily_target_weight' => 8, 'output_volume_weight' => 9, 'task_giving_weight' => 20, // sums to 110
        ])->assertStatus(422);

        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 16, 'on_time_weight' => 16, 'revision_weight' => 11,
            'sales_weight' => 10, 'satisfaction_weight' => 10, 'client_care_weight' => 10,
            'daily_target_weight' => 8, 'output_volume_weight' => 9, 'task_giving_weight' => 10, // sums to 100
        ])->assertOk();

        $this->assertSame(10, KpiWeightConfig::where('scope_type', 'global')->value('task_giving_weight'));
    }

    public function test_the_configuration_screen_shows_task_giving(): void
    {
        $this->actingAs($this->manager)->get(route('performance.config'))
            ->assertOk()
            ->assertSee('Task Giving')
            ->assertSee('name="task_giving_weight"', false);
    }

    public function test_the_scorecard_shows_task_giving_quality(): void
    {
        $giver = $this->worker();
        Task::create([
            'title' => 'x', 'priority' => 'Medium', 'status' => 'Open', 'type' => 'Other',
            'created_by' => $giver->id, 'due_date' => '2026-09-25',
        ]);

        $this->actingAs($this->manager)->get(route('performance.show', ['user' => $giver, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Task Giving Quality')
            ->assertSee('Clean-first-time rate');
    }

    public function test_the_scoreboard_renders_the_task_giving_column(): void
    {
        $this->actingAs($this->manager)->get(route('performance.index'))
            ->assertOk()
            ->assertSee('Task Giving');
    }
}
