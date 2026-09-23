<?php

namespace Tests\Unit;

use App\Models\DailyTarget;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Daily Target scoring formula in isolation — elapsed-day arithmetic,
 * the forgiven/scored boundary, and multi-scope averaging — called directly
 * against PerformanceCalculationService rather than through a route. The
 * HTTP-level behavior (config screen, authorization, validation, rendering)
 * is covered separately in Tests\Feature\DailyTargetPerformanceTest.
 */
class DailyTargetScoringTest extends TestCase
{
    use RefreshDatabase;

    private User $sam;
    private User $manager;
    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sam     = User::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->flow    = Flow::create(['name' => 'Test Flow', 'is_active' => true]);
    }

    private function task(string $dueDate, string $status = 'Completed'): Task
    {
        return Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'assigned_to' => $this->sam->id, 'created_by' => $this->manager->id,
            'due_date' => $dueDate, 'completion_date' => $status === 'Completed' ? $dueDate : null,
        ]);
    }

    private function achievement(string $period = '2026-09'): ?array
    {
        return app(PerformanceCalculationService::class)->dailyTargetAchievement($this->sam, $period);
    }

    // ── Elapsed-day arithmetic ───────────────────────────────────────────
    //
    // Regression coverage for a real bug: Carbon 3's diffInDays() returns a
    // float, and an earlier version of this formula mixed a midday "now"
    // with a midnight period start, giving 20.5 elapsed days instead of 20.

    public function test_elapsed_days_is_a_whole_number_on_the_first_day_of_the_period(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 23:59:00'));
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);

        $this->assertSame(1, $this->achievement()['elapsed_days']);
    }

    public function test_elapsed_days_is_whole_regardless_of_the_time_of_day(): void
    {
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);

        $this->travelTo(Carbon::parse('2026-09-15 00:00:01'));
        $justAfterMidnight = $this->achievement()['elapsed_days'];

        $this->travelTo(Carbon::parse('2026-09-15 23:59:59'));
        $justBeforeMidnight = $this->achievement()['elapsed_days'];

        $this->assertSame(15, $justAfterMidnight);
        $this->assertSame($justAfterMidnight, $justBeforeMidnight);
    }

    public function test_a_closed_period_uses_the_full_month_not_just_days_elapsed_in_it(): void
    {
        // "Now" is well into September; scoring August should use all 31 days.
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 1]);

        $this->assertSame(31, $this->achievement('2026-08')['elapsed_days']);
    }

    // ── The forgiven / scored boundary ───────────────────────────────────

    public function test_available_exactly_equal_to_the_target_is_not_forgiven(): void
    {
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00')); // elapsed_days = 5
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 2]); // target so far = 10
        foreach (range(1, 10) as $i) {
            $this->task('2026-09-01', 'Pending'); // available = 10, none completed
        }

        $scope = $this->achievement()['scopes']['task'];

        $this->assertSame(10, $scope['target_so_far']);
        $this->assertSame(10, $scope['available']);
        $this->assertFalse($scope['forgiven']);
        $this->assertSame(0.0, $scope['pct']);
    }

    public function test_one_short_of_the_target_is_forgiven(): void
    {
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00')); // elapsed_days = 5
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 2]); // target so far = 10
        foreach (range(1, 9) as $i) {
            $this->task('2026-09-01', 'Pending');
        }

        $scope = $this->achievement()['scopes']['task'];

        $this->assertSame(9, $scope['available']);
        $this->assertTrue($scope['forgiven']);
        $this->assertSame(100.0, $scope['pct']);
    }

    // ── Robustness ───────────────────────────────────────────────────────

    public function test_a_scope_the_system_no_longer_recognizes_is_forgiven_rather_than_crashing(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));
        // Simulates a row left over from a scope that was since removed —
        // never actually reachable through the config screen's validation.
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'retired-scope', 'target_quantity' => 5]);

        $scope = $this->achievement()['scopes']['retired-scope'];

        $this->assertSame(0, $scope['available']);
        $this->assertSame(0, $scope['completed']);
        $this->assertTrue($scope['forgiven']);
        $this->assertSame(100.0, $scope['pct']);
    }

    // ── Multi-scope averaging ────────────────────────────────────────────

    public function test_the_overall_score_is_the_precise_average_across_every_scope(): void
    {
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00')); // elapsed_days = 5
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'task', 'target_quantity' => 2]); // target so far = 10
        DailyTarget::create(['user_id' => $this->sam->id, 'scope' => 'workflow', 'target_quantity' => 1]); // target so far = 5

        // Task: 10 due, 5 done -> 50%.
        foreach (range(1, 10) as $i) {
            $this->task('2026-09-01', $i <= 5 ? 'Completed' : 'Pending');
        }
        // Workflow: 5 due, 1 done -> 20%.
        foreach (range(1, 5) as $i) {
            FlowItem::create([
                'flow_id' => $this->flow->id, 'title' => 'Item',
                'status' => $i === 1 ? FlowItem::STATUS_COMPLETED : FlowItem::STATUS_OPEN,
                'assigned_to' => $this->sam->id, 'created_by' => $this->manager->id,
                'due_date' => '2026-09-01', 'completed_at' => $i === 1 ? '2026-09-01' : null,
            ]);
        }

        $result = $this->achievement();

        $this->assertSame(50.0, $result['scopes']['task']['pct']);
        $this->assertSame(20.0, $result['scopes']['workflow']['pct']);
        $this->assertSame(35.0, $result['pct']); // (50 + 20) / 2
    }
}
