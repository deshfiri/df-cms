<?php

namespace Tests\Feature;

use App\Models\DailyTarget;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Daily Target: an optional, standing "N a day" goal an admin/manager sets
 * for an individual employee, per scope of work (Performance → Configuration
 * → Daily Targets — checkboxes for Tasks / Workflow Items, a quantity for
 * each checked one). If less work of a scope was actually due than the quota,
 * that scope is forgiven and counts as 100% — it only costs the employee once
 * there was enough work and they didn't get through it. See
 * PerformanceCalculationService::dailyTargetAchievement().
 */
class DailyTargetPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $sam;
    private User $manager;
    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        // 20 days into the period — periodBounds() gives 2026-09-01..30, so
        // "elapsed" is deterministic instead of depending on the real clock.
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));

        foreach (['view performance', 'manage performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->sam     = User::factory()->create(['is_active' => true, 'name' => 'Sales Sam']);
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view performance', 'manage performance']);
        $this->flow    = Flow::create(['name' => 'Test Flow', 'is_active' => true]);
    }

    private function task(User $assignee, string $dueDate, string $status = 'Completed'): Task
    {
        $task = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'created_by' => $this->manager->id,
            'due_date' => $dueDate, 'completion_date' => $status === 'Completed' ? $dueDate : null,
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    private function flowItem(User $assignee, string $dueDate, string $status = FlowItem::STATUS_COMPLETED): FlowItem
    {
        return FlowItem::create([
            'flow_id' => $this->flow->id, 'title' => 'Item', 'status' => $status,
            'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
            'due_date' => $dueDate, 'completed_at' => $status === FlowItem::STATUS_COMPLETED ? $dueDate : null,
        ]);
    }

    private function achievement(User $user): ?array
    {
        return app(PerformanceCalculationService::class)->dailyTargetAchievement($user, self::PERIOD);
    }

    // ── Calculation ──────────────────────────────────────────────────────

    public function test_nobody_with_no_target_is_scored_on_it(): void
    {
        $this->task($this->sam, '2026-09-10');

        $this->assertNull($this->achievement($this->sam));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($this->sam, self::PERIOD)['scores']['daily_target']);
    }

    public function test_a_scope_is_forgiven_when_less_work_was_due_than_the_target(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 5]);
        // Quota so far is 5 × 20 elapsed days = 100; only 3 tasks were ever due.
        foreach (range(1, 3) as $day) {
            $this->task($this->sam, sprintf('2026-09-%02d', $day));
        }

        $scope = $this->achievement($this->sam)['scopes']['task'];

        $this->assertSame(100, $scope['target_so_far']);
        $this->assertSame(3, $scope['available']);
        $this->assertTrue($scope['forgiven']);
        $this->assertSame(100.0, $scope['pct']);
    }

    public function test_a_scope_scores_normally_once_enough_work_was_due(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);
        // Quota so far is 1 × 20 = 20. 25 tasks are due (plenty), only 10 done.
        foreach (range(1, 25) as $i) {
            $this->task($this->sam, '2026-09-01', $i <= 10 ? 'Completed' : 'Pending');
        }

        $scope = $this->achievement($this->sam)['scopes']['task'];

        $this->assertSame(20, $scope['target_so_far']);
        $this->assertSame(25, $scope['available']);
        $this->assertFalse($scope['forgiven']);
        $this->assertSame(10, $scope['completed']);
        $this->assertSame(50.0, $scope['pct']);
    }

    public function test_achievement_is_capped_at_100_percent(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);
        foreach (range(1, 25) as $i) {
            $this->task($this->sam, '2026-09-01');
        }

        $this->assertSame(100.0, $this->achievement($this->sam)['scopes']['task']['pct']);
    }

    public function test_tasks_due_outside_the_period_do_not_count(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);
        $this->task($this->sam, '2026-08-31');
        $this->task($this->sam, '2026-10-01');

        $this->assertSame(0, $this->achievement($this->sam)['scopes']['task']['available']);
    }

    public function test_only_tasks_directly_assigned_to_them_count_not_shared_involvement(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);
        $colleague = User::factory()->create(['is_active' => true]);
        $this->task($colleague, '2026-09-10'); // assigned to someone else entirely

        $this->assertSame(0, $this->achievement($this->sam)['scopes']['task']['available']);
    }

    public function test_the_workflow_scope_is_measured_from_flow_items(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'workflow', 'target_quantity' => 1]);
        // Quota so far is 20. 20 items due, 15 completed.
        foreach (range(1, 20) as $i) {
            $this->flowItem($this->sam, '2026-09-01', $i <= 15 ? FlowItem::STATUS_COMPLETED : FlowItem::STATUS_OPEN);
        }

        $scope = $this->achievement($this->sam)['scopes']['workflow'];

        $this->assertSame(20, $scope['available']);
        $this->assertSame(15, $scope['completed']);
        $this->assertSame(75.0, $scope['pct']);
    }

    public function test_multiple_scopes_are_averaged_into_the_overall_score(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'workflow', 'target_quantity' => 5]);

        // Task: quota 20, 20 due, 10 done -> 50%.
        foreach (range(1, 20) as $i) {
            $this->task($this->sam, '2026-09-01', $i <= 10 ? 'Completed' : 'Pending');
        }
        // Workflow: quota 100, only 2 items ever due -> forgiven, 100%.
        $this->flowItem($this->sam, '2026-09-05');
        $this->flowItem($this->sam, '2026-09-06');

        $result = $this->achievement($this->sam);

        $this->assertSame(50.0, $result['scopes']['task']['pct']);
        $this->assertSame(100.0, $result['scopes']['workflow']['pct']);
        $this->assertSame(75.0, $result['pct']); // average of 50 and 100
    }

    public function test_it_counts_in_the_final_score_with_its_weight(): void
    {
        // Every other weight is 0: the same completed task that gives daily
        // target its data also has task/on-time/revision data of its own, so
        // isolating daily target means giving nothing else any weight at all.
        KpiWeightConfig::create([
            'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 0, 'on_time_weight' => 0, 'revision_weight' => 0,
            'sales_weight' => 0, 'satisfaction_weight' => 0, 'client_care_weight' => 0,
            'daily_target_weight' => 100,
        ]);
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);
        $this->task($this->sam, '2026-09-10');

        $result = app(PerformanceCalculationService::class)->finalScore($this->sam, self::PERIOD);

        $this->assertSame(['daily_target' => 100.0], $result['weights_used']);
        $this->assertSame($result['scores']['daily_target'], $result['final_score']);

        // Weighted 0 itself, it is shown but can't move the score.
        KpiWeightConfig::query()->update(['daily_target_weight' => 0]);
        $zero = app(PerformanceCalculationService::class)->finalScore($this->sam, self::PERIOD);
        $this->assertNotNull($zero['scores']['daily_target']);
        $this->assertNull($zero['final_score']);
    }

    public function test_batch_scoring_matches_scoring_one_by_one(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 2]);
        DailyTarget::create(['user_id' => $other->id, 'scope' => 'workflow', 'target_quantity' => 3]);
        $this->task($this->sam, '2026-09-05');
        $this->flowItem($other, '2026-09-06');

        $one = [$this->achievement($this->sam), $this->achievement($other)];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$this->sam, $other]), self::PERIOD);

        $this->assertSame($one, [
            $batched->dailyTargetAchievement($this->sam, self::PERIOD),
            $batched->dailyTargetAchievement($other, self::PERIOD),
        ]);
    }

    // ── Configuration ────────────────────────────────────────────────────

    public function test_manage_performance_can_set_multiple_scopes_at_once(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id,
                'scopes' => ['task', 'workflow'],
                'quantities' => ['task' => 5, 'workflow' => 3],
            ])->assertOk();

        $this->assertSame(5, DailyTarget::where('user_id', $this->sam->id)->where('scope', 'task')->value('target_quantity'));
        $this->assertSame(3, DailyTarget::where('user_id', $this->sam->id)->where('scope', 'workflow')->value('target_quantity'));
    }

    public function test_setting_it_again_replaces_rather_than_adds(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.daily-targets.store'), [
            'user_id' => $this->sam->id, 'scopes' => ['task', 'workflow'], 'quantities' => ['task' => 5, 'workflow' => 3],
        ])->assertOk();

        // Second save only checks "task" — workflow should be dropped, and
        // reducing the number is how an admin "reduces" an assigned target.
        $this->actingAs($this->manager)->postJson(route('performance.config.daily-targets.store'), [
            'user_id' => $this->sam->id, 'scopes' => ['task'], 'quantities' => ['task' => 2],
        ])->assertOk();

        $this->assertSame(1, DailyTarget::where('user_id', $this->sam->id)->count());
        $this->assertSame(2, DailyTarget::where('user_id', $this->sam->id)->where('scope', 'task')->value('target_quantity'));
    }

    public function test_clearing_removes_every_scope_for_that_user(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.daily-targets.store'), [
            'user_id' => $this->sam->id, 'scopes' => ['task', 'workflow'], 'quantities' => ['task' => 5, 'workflow' => 3],
        ])->assertOk();

        $this->actingAs($this->manager)
            ->deleteJson(route('performance.config.daily-targets.destroy', $this->sam))
            ->assertOk();

        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id]);
    }

    public function test_setting_a_daily_target_requires_manage_performance(): void
    {
        $worker = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view performance');

        $this->actingAs($worker)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'scopes' => ['task'], 'quantities' => ['task' => 5],
            ])->assertForbidden();

        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id]);
    }

    public function test_every_checked_scope_needs_a_valid_quantity(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'scopes' => ['task'], 'quantities' => [],
            ])
            ->assertStatus(422);

        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'scopes' => ['task'], 'quantities' => ['task' => 0],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantities.task');

        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id]);
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'scopes' => ['not-a-real-scope'], 'quantities' => ['not-a-real-scope' => 5],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scopes.0');
    }

    public function test_the_scorecard_and_configuration_show_daily_target(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 5]);
        $this->task($this->sam, '2026-09-10');

        $this->actingAs($this->manager)->get(route('performance.show', ['user' => $this->sam, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Daily Target')
            ->assertSee('Overall achievement');

        $this->actingAs($this->manager)->get(route('performance.config'))
            ->assertOk()
            ->assertSee('Daily Targets')
            ->assertSee('name="daily_target_weight"', false);
    }
}
