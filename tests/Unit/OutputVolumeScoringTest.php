<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Output Volume: absolute output relative to the most productive person in
 * the company that period, averaged across every scope of work tracked
 * (tasks, workflow items, client handling) — not tasks alone. Task
 * Completion (and its like) already measure a RATE, where finishing
 * everything you were given hits 100% whether that was 5 tasks or 50; this
 * is what makes higher raw output actually count for more. Called directly
 * against PerformanceCalculationService. HTTP-level behavior (weight
 * config, rendering) is covered in Tests\Feature\OutputVolumePerformanceTest.
 */
class OutputVolumeScoringTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;
    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->flow    = Flow::create(['name' => 'Test Flow', 'is_active' => true]);
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

    private function flowItems(User $assignee, int $count, string $status = FlowItem::STATUS_COMPLETED): void
    {
        foreach (range(1, $count) as $i) {
            FlowItem::create([
                'flow_id' => $this->flow->id, 'title' => 'Item', 'status' => $status,
                'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
                'due_date' => '2026-09-10', 'completed_at' => $status === FlowItem::STATUS_COMPLETED ? '2026-09-10' : null,
            ]);
        }
    }

    private function clientsFor(User $owner, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
            Client::create([
                'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client ' . uniqid(), 'brand_name' => 'Brand',
                'category_id' => $category->id, 'assigned_to' => $owner->id,
            ]);
        }
    }

    private function volume(User $user): ?array
    {
        return app(PerformanceCalculationService::class)->outputVolume($user, self::PERIOD);
    }

    // ── The motivating scenario ──────────────────────────────────────────

    /**
     * Two people both finish 100% of what they were given, but one did
     * twice the work. Before this feature they were indistinguishable; now
     * the higher-output person scores higher — on the task scope alone
     * here, exactly the case that was reported.
     */
    public function test_two_people_both_at_100_percent_completion_rate_score_differently_by_volume(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        $this->tasks($userA, 5);  // all 5 completed -> 100% rate
        $this->tasks($userB, 10); // all 10 completed -> 100% rate

        $calc = app(PerformanceCalculationService::class);
        $this->assertSame(100.0, $calc->taskCompletion($userA, self::PERIOD)['completion_pct']);
        $this->assertSame(100.0, $calc->taskCompletion($userB, self::PERIOD)['completion_pct']);

        $volumeA = $this->volume($userA);
        $volumeB = $this->volume($userB);

        $this->assertSame(50.0, $volumeA['scopes']['task']['pct']);
        $this->assertSame(100.0, $volumeB['scopes']['task']['pct']);
        $this->assertGreaterThan($volumeA['pct'], $volumeB['pct']);
    }

    public function test_nobody_with_nothing_to_measure_in_any_scope_is_scored_on_it(): void
    {
        $idle = User::factory()->create(['is_active' => true]);

        $this->assertNull($this->volume($idle));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($idle, self::PERIOD)['scores']['output_volume']);
    }

    // ── Each scope on its own ────────────────────────────────────────────

    public function test_the_workflow_scope_measures_completed_items_against_the_leader(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $mine   = User::factory()->create(['is_active' => true]);
        $this->flowItems($leader, 8);
        $this->flowItems($mine, 2);

        $scope = $this->volume($mine)['scopes']['workflow'];

        $this->assertSame(2.0, $scope['mine']);
        $this->assertSame(8.0, $scope['cohort_max']);
        $this->assertSame(25.0, $scope['pct']);
        $this->assertSame('Workflow Items', $scope['label']);
    }

    public function test_the_client_handling_scope_measures_portfolio_size_against_the_leader(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $mine   = User::factory()->create(['is_active' => true]);
        $this->clientsFor($leader, 4);
        $this->clientsFor($mine, 1);

        $scope = $this->volume($mine)['scopes']['client_handling'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(4.0, $scope['cohort_max']);
        $this->assertSame(25.0, $scope['pct']);
        $this->assertSame('Client Handling', $scope['label']);
    }

    public function test_client_handling_is_not_period_scoped(): void
    {
        // Portfolio is a standing assignment, not something that happened
        // this month — it should read the same regardless of which period
        // is being scored.
        $user = User::factory()->create(['is_active' => true]);
        $this->clientsFor($user, 3);

        $calc = app(PerformanceCalculationService::class);
        $thisMonth = $calc->outputVolume($user, '2026-09')['scopes']['client_handling']['mine'];
        $lastMonth = $calc->outputVolume($user, '2026-08')['scopes']['client_handling']['mine'];

        $this->assertSame(3.0, $thisMonth);
        $this->assertSame($thisMonth, $lastMonth);
    }

    public function test_the_top_performer_in_a_scope_always_scores_exactly_100(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $this->tasks($leader, 7);

        $this->assertSame(100.0, $this->volume($leader)['scopes']['task']['pct']);
    }

    // ── Combining scopes ─────────────────────────────────────────────────

    /**
     * A scope this person has nothing to show in (no tasks due, no workflow
     * items due, no clients of their own) is left out of their average
     * rather than counted as a 0 — same "no data, no penalty" rule as every
     * other optional KPI.
     */
    public function test_a_scope_with_no_data_is_left_out_of_the_average_not_scored_zero(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $mine   = User::factory()->create(['is_active' => true]);
        $this->tasks($leader, 10);
        $this->tasks($mine, 5); // task-only: no workflow items, no clients for either

        $result = $this->volume($mine);

        $this->assertArrayHasKey('task', $result['scopes']);
        $this->assertArrayNotHasKey('workflow', $result['scopes']);
        $this->assertArrayNotHasKey('client_handling', $result['scopes']);
        $this->assertSame(50.0, $result['pct']); // the task scope alone, not averaged down by absent scopes
    }

    public function test_the_overall_score_is_the_average_of_every_applicable_scope(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $mine   = User::factory()->create(['is_active' => true]);

        $this->tasks($leader, 10);
        $this->tasks($mine, 5); // 50%
        $this->flowItems($leader, 4);
        $this->flowItems($mine, 4); // 100%
        $this->clientsFor($leader, 4);
        $this->clientsFor($mine, 1); // 25%

        $result = $this->volume($mine);

        $this->assertSame(50.0, $result['scopes']['task']['pct']);
        $this->assertSame(100.0, $result['scopes']['workflow']['pct']);
        $this->assertSame(25.0, $result['scopes']['client_handling']['pct']);
        $this->assertSame(58.33, $result['pct']); // (50 + 100 + 25) / 3
    }

    // ── Company-wide, batch-consistent ───────────────────────────────────

    /**
     * The cohort is always company-wide, independent of whatever subset was
     * passed to prefetch() — a department-filtered scoreboard view must not
     * change what "the company's highest" means for a given period, in any
     * scope.
     */
    public function test_every_scopes_cohort_is_company_wide_even_when_prefetch_covers_a_subset(): void
    {
        $inFilter    = User::factory()->create(['is_active' => true]);
        $outOfFilter = User::factory()->create(['is_active' => true]); // the real leader, outside the prefetch

        $this->tasks($inFilter, 3);
        $this->tasks($outOfFilter, 9);
        $this->flowItems($inFilter, 1);
        $this->flowItems($outOfFilter, 5);
        $this->clientsFor($inFilter, 1);
        $this->clientsFor($outOfFilter, 4);

        $calc = app(PerformanceCalculationService::class);
        $calc->prefetch(collect([$inFilter]), self::PERIOD); // simulates a department-filtered scoreboard run

        $result = $calc->outputVolume($inFilter, self::PERIOD);

        $this->assertSame(9.0, $result['scopes']['task']['cohort_max']);
        $this->assertSame(5.0, $result['scopes']['workflow']['cohort_max']);
        $this->assertSame(4.0, $result['scopes']['client_handling']['cohort_max']);
    }

    public function test_batch_scoring_matches_scoring_one_by_one(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        $this->tasks($userA, 4);
        $this->tasks($userB, 8);
        $this->flowItems($userA, 2);
        $this->clientsFor($userB, 3);

        $one = [$this->volume($userA), $this->volume($userB)];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$userA, $userB]), self::PERIOD);

        $this->assertSame($one, [
            $batched->outputVolume($userA, self::PERIOD),
            $batched->outputVolume($userB, self::PERIOD),
        ]);
    }
}
