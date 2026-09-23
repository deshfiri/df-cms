<?php

namespace Tests\Feature;

use App\Models\DailyTarget;
use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Daily Target: an optional, standing "N tasks a day" goal an admin/manager
 * sets for an individual employee (Performance → Configuration → Daily
 * Targets). No target set means the KPI is left out of their score entirely
 * — see PerformanceCalculationService::dailyTargetAchievement().
 */
class DailyTargetPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $sam;
    private User $manager;

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
    }

    private function completedTask(User $assignee, string $dueDate): Task
    {
        return Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
            'due_date' => $dueDate, 'completion_date' => $dueDate,
        ]);
    }

    private function achievement(User $user): ?array
    {
        return app(PerformanceCalculationService::class)->dailyTargetAchievement($user, self::PERIOD);
    }

    // ── Calculation ──────────────────────────────────────────────────────

    public function test_nobody_with_no_target_is_scored_on_it(): void
    {
        $this->completedTask($this->sam, '2026-09-10');

        $this->assertNull($this->achievement($this->sam));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($this->sam, self::PERIOD)['scores']['daily_target']);
    }

    public function test_achievement_is_completed_work_against_target_times_days_elapsed(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'target_tasks_per_day' => 1]);
        foreach (range(1, 10) as $day) {
            $this->completedTask($this->sam, sprintf('2026-09-%02d', $day));
        }

        $result = $this->achievement($this->sam);

        // "Now" is the 20th, so 20 days have elapsed; target so far = 1 × 20 = 20.
        $this->assertSame(1, $result['target_per_day']);
        $this->assertSame(20, $result['elapsed_days']);
        $this->assertSame(20, $result['target_so_far']);
        $this->assertSame(10.0, $result['completed']);
        $this->assertSame(50.0, $result['pct']);
    }

    public function test_achievement_is_capped_at_100_percent(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'target_tasks_per_day' => 1]);
        foreach (range(1, 25) as $i) {
            $this->completedTask($this->sam, '2026-09-01');
        }

        // 25 completed against a target so far of 20 (1/day × 20 elapsed days).
        $this->assertSame(100.0, $this->achievement($this->sam)['pct']);
    }

    public function test_tasks_due_outside_the_period_do_not_count(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'target_tasks_per_day' => 1]);
        $this->completedTask($this->sam, '2026-08-31');
        $this->completedTask($this->sam, '2026-10-01');

        $this->assertSame(0.0, $this->achievement($this->sam)['completed']);
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
        DailyTarget::create(['user_id' => $this->sam->id, 'target_tasks_per_day' => 1]);
        $this->completedTask($this->sam, '2026-09-10');

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
        DailyTarget::create(['user_id' => $this->sam->id, 'target_tasks_per_day' => 2]);
        DailyTarget::create(['user_id' => $other->id, 'target_tasks_per_day' => 3]);
        $this->completedTask($this->sam, '2026-09-05');
        $this->completedTask($other, '2026-09-06');

        $one = [$this->achievement($this->sam), $this->achievement($other)];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$this->sam, $other]), self::PERIOD);

        $this->assertSame($one, [
            $batched->dailyTargetAchievement($this->sam, self::PERIOD),
            $batched->dailyTargetAchievement($other, self::PERIOD),
        ]);
    }

    // ── Configuration ────────────────────────────────────────────────────

    public function test_manage_performance_can_set_and_clear_a_daily_target(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'target_tasks_per_day' => 5,
            ])->assertOk();

        $target = DailyTarget::where('user_id', $this->sam->id)->first();
        $this->assertSame(5, $target->target_tasks_per_day);

        $this->actingAs($this->manager)
            ->deleteJson(route('performance.config.daily-targets.destroy', $target))
            ->assertOk();
        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id]);
    }

    public function test_setting_a_daily_target_requires_manage_performance(): void
    {
        $worker = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view performance');

        $this->actingAs($worker)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'target_tasks_per_day' => 5,
            ])->assertForbidden();

        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id]);
    }

    public function test_the_target_must_be_a_positive_integer(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'target_tasks_per_day' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_tasks_per_day');

        $this->actingAs($this->manager)
            ->postJson(route('performance.config.daily-targets.store'), [
                'user_id' => $this->sam->id, 'target_tasks_per_day' => 'lots',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_tasks_per_day');

        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id]);
    }

    public function test_setting_it_again_updates_rather_than_duplicates(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.daily-targets.store'), [
            'user_id' => $this->sam->id, 'target_tasks_per_day' => 5,
        ])->assertOk();
        $this->actingAs($this->manager)->postJson(route('performance.config.daily-targets.store'), [
            'user_id' => $this->sam->id, 'target_tasks_per_day' => 8,
        ])->assertOk();

        $this->assertSame(1, DailyTarget::where('user_id', $this->sam->id)->count());
        $this->assertSame(8, DailyTarget::where('user_id', $this->sam->id)->value('target_tasks_per_day'));
    }

    public function test_the_scorecard_and_configuration_show_daily_target(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'target_tasks_per_day' => 5]);
        $this->completedTask($this->sam, '2026-09-10');

        $this->actingAs($this->manager)->get(route('performance.show', ['user' => $this->sam, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Daily Target')
            ->assertSee('Target / day');

        $this->actingAs($this->manager)->get(route('performance.config'))
            ->assertOk()
            ->assertSee('Daily Targets')
            ->assertSee('name="daily_target_weight"', false);
    }
}
