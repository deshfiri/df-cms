<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Task Volume: completed-task credit relative to the most productive person
 * in the company that period. Task Completion already measures the RATE
 * (finish everything you're given, you hit 100% whether that was 5 tasks or
 * 50) — this is what makes higher raw output actually count for more, called
 * directly against PerformanceCalculationService. HTTP-level behavior (weight
 * config, rendering) is covered in Tests\Feature\TaskVolumePerformanceTest.
 */
class TaskVolumeScoringTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        $this->manager = User::factory()->create(['is_active' => true]);
    }

    private function tasks(User $assignee, int $count, string $status = 'Completed'): void
    {
        foreach (range(1, $count) as $i) {
            Task::create([
                'title' => 'Task', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
                'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
                'due_date' => '2026-09-10', 'completion_date' => $status === 'Completed' ? '2026-09-10' : null,
            ]);
        }
    }

    private function volume(User $user): ?array
    {
        return app(PerformanceCalculationService::class)->taskVolume($user, self::PERIOD);
    }

    /**
     * The exact scenario that motivated this feature: two people both
     * finish 100% of what they were given, but one did twice the work.
     * Before Task Volume existed they were indistinguishable; now the
     * higher-output person scores higher.
     */
    public function test_two_people_both_at_100_percent_completion_rate_score_differently_by_volume(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        $this->tasks($userA, 5);  // all 5 completed -> 100% rate
        $this->tasks($userB, 10); // all 10 completed -> 100% rate

        $calc = app(PerformanceCalculationService::class);
        $rateA = $calc->taskCompletion($userA, self::PERIOD)['completion_pct'];
        $rateB = $calc->taskCompletion($userB, self::PERIOD)['completion_pct'];

        // The old behavior this feature fixes: identical rates.
        $this->assertSame(100.0, $rateA);
        $this->assertSame(100.0, $rateB);

        // The new behavior: volume tells them apart. B did the most in the
        // company this period, so B is the 100% benchmark; A did half as
        // much, so A scores half.
        $volumeA = $this->volume($userA);
        $volumeB = $this->volume($userB);

        $this->assertSame(5.0, $volumeA['completed']);
        $this->assertSame(10.0, $volumeB['completed']);
        $this->assertSame(10.0, $volumeA['cohort_max']);
        $this->assertSame(10.0, $volumeB['cohort_max']);
        $this->assertSame(50.0, $volumeA['pct']);
        $this->assertSame(100.0, $volumeB['pct']);
        $this->assertGreaterThan($volumeA['pct'], $volumeB['pct']);
    }

    public function test_nobody_with_no_tasks_in_the_period_is_scored_on_it(): void
    {
        $idle = User::factory()->create(['is_active' => true]);

        $this->assertNull($this->volume($idle));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($idle, self::PERIOD)['scores']['task_volume']);
    }

    public function test_partial_completion_scores_proportionally_against_the_leader(): void
    {
        $leader  = User::factory()->create(['is_active' => true]);
        $partial = User::factory()->create(['is_active' => true]);
        $this->tasks($leader, 20);
        $this->tasks($partial, 20, 'Pending');
        // Give the partial performer 5 completed among their 20 pending ones.
        $this->tasks($partial, 5, 'Completed');

        $result = $this->volume($partial);

        $this->assertSame(5.0, $result['completed']);
        $this->assertSame(20.0, $result['cohort_max']);
        $this->assertSame(25.0, $result['pct']); // 5 / 20 * 100
    }

    public function test_the_top_performer_always_scores_exactly_100(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $this->tasks($leader, 7);

        $this->assertSame(100.0, $this->volume($leader)['pct']);
    }

    /**
     * The cohort is always company-wide, independent of whatever subset was
     * passed to prefetch() — a department-filtered scoreboard view must not
     * change what "the company's highest" means for a given period.
     */
    public function test_the_cohort_is_company_wide_even_when_prefetch_only_covers_a_subset(): void
    {
        $inFilter    = User::factory()->create(['is_active' => true]);
        $outOfFilter = User::factory()->create(['is_active' => true]);
        $this->tasks($inFilter, 3);
        $this->tasks($outOfFilter, 9); // the real company leader, outside the prefetched cohort

        $calc = app(PerformanceCalculationService::class);
        $calc->prefetch(collect([$inFilter]), self::PERIOD); // simulates a department-filtered scoreboard run

        $result = $calc->taskVolume($inFilter, self::PERIOD);

        $this->assertSame(9.0, $result['cohort_max']);
        $this->assertSame(33.33, $result['pct']); // 3 / 9 * 100
    }

    public function test_batch_scoring_matches_scoring_one_by_one(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        $this->tasks($userA, 4);
        $this->tasks($userB, 8);

        $one = [$this->volume($userA), $this->volume($userB)];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$userA, $userB]), self::PERIOD);

        $this->assertSame($one, [
            $batched->taskVolume($userA, self::PERIOD),
            $batched->taskVolume($userB, self::PERIOD),
        ]);
    }
}
