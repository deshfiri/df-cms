<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Output Volume, averaged across every scope of work tracked (workflow
 * items, client handling). The two scopes score differently now:
 *
 * - Workflow Items' Result is a pure "cleared your queue" compliance check
 *   — 100% unless something claimed is stalled — never reduced just because
 *   a colleague moved more items. `mine`/`cohort_max` are still tracked and
 *   shown for context. See WorkflowScopeWithClientAccessTest for the
 *   stalled-item penalty itself.
 * - Client Handling is still a straight ratio against the company's largest
 *   portfolio, uncapped by any compliance concept — this is where "more raw
 *   output actually counts for more" still lives for Output Volume.
 *
 * Deliberately has no "task" scope: Task Completion already scores every
 * task an assignee is given, so a task scope here would credit the same
 * completed tasks a second time. See
 * PerformanceCalculationService::outputVolume().
 *
 * Called directly against PerformanceCalculationService. HTTP-level
 * behavior (weight config, rendering) is covered in
 * Tests\Feature\OutputVolumePerformanceTest.
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
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->flow    = Flow::create(['name' => 'Test Flow', 'is_active' => true]);
    }

    /** The Client Handling scope is a client-permission concept — see WorkflowVolumeNoClientAccessTest. */
    private function clientHandler(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view clients')->fresh();
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
     * twice the work. On Client Handling — the scope that still ranks by
     * raw volume — the higher-output person scores higher; on Workflow
     * Items, both are simply caught up and both show 100%.
     */
    public function test_two_people_both_at_100_percent_completion_rate_score_differently_by_client_handling_volume(): void
    {
        $userA = $this->clientHandler();
        $userB = $this->clientHandler();
        $this->flowItems($userA, 5);  // all 5 completed, nothing stalled -> 100% Result either way
        $this->flowItems($userB, 10);
        $this->clientsFor($userA, 2);
        $this->clientsFor($userB, 4); // twice the portfolio

        $volumeA = $this->volume($userA);
        $volumeB = $this->volume($userB);

        $this->assertSame(100.0, $volumeA['scopes']['workflow']['pct']);
        $this->assertSame(100.0, $volumeB['scopes']['workflow']['pct']);
        $this->assertSame(50.0, $volumeA['scopes']['client_handling']['pct']);
        $this->assertSame(100.0, $volumeB['scopes']['client_handling']['pct']);
        $this->assertGreaterThan($volumeA['pct'], $volumeB['pct']);
    }

    public function test_nobody_with_nothing_to_measure_in_any_scope_is_scored_on_it(): void
    {
        $idle = User::factory()->create(['is_active' => true]);

        $this->assertNull($this->volume($idle));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($idle, self::PERIOD)['scores']['output_volume']);
    }

    /** Tasks are Task Completion's job, not Output Volume's — see the class docblock. */
    public function test_output_volume_has_no_task_scope(): void
    {
        $leader = User::factory()->create(['is_active' => true]);
        $this->flowItems($leader, 3);

        $this->assertArrayNotHasKey('task', $this->volume($leader)['scopes']);
        $this->assertArrayNotHasKey('task', PerformanceCalculationService::VOLUME_SCOPE_LABELS);
    }

    // ── Each scope on its own ────────────────────────────────────────────

    public function test_the_workflow_scope_tracks_volume_but_scores_on_compliance(): void
    {
        $leader = $this->clientHandler();
        $mine   = $this->clientHandler();
        $this->flowItems($leader, 8);
        $this->flowItems($mine, 2);

        $scope = $this->volume($mine)['scopes']['workflow'];

        // Theirs / company's highest are still tracked and shown...
        $this->assertSame(2.0, $scope['mine']);
        $this->assertSame(8.0, $scope['cohort_max']);
        // ...but Result doesn't come from that ratio — nothing is stalled
        // (these are completed items), so it's 100%.
        $this->assertSame(100.0, $scope['pct']);
        $this->assertSame('Workflow Items', $scope['label']);
    }

    public function test_the_client_handling_scope_measures_portfolio_size_against_the_leader(): void
    {
        $leader = $this->clientHandler();
        $mine   = $this->clientHandler();
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
        $user = $this->clientHandler();
        $this->clientsFor($user, 3);

        $calc = app(PerformanceCalculationService::class);
        $thisMonth = $calc->outputVolume($user, '2026-09')['scopes']['client_handling']['mine'];
        $lastMonth = $calc->outputVolume($user, '2026-08')['scopes']['client_handling']['mine'];

        $this->assertSame(3.0, $thisMonth);
        $this->assertSame($thisMonth, $lastMonth);
    }

    public function test_the_top_performer_in_client_handling_always_scores_exactly_100(): void
    {
        $leader = $this->clientHandler();
        $this->clientsFor($leader, 7);

        $this->assertSame(100.0, $this->volume($leader)['scopes']['client_handling']['pct']);
    }

    // ── Combining scopes ─────────────────────────────────────────────────

    /**
     * A scope this person has nothing to show in (no workflow items due, no
     * clients of their own) is left out of their average rather than
     * counted as a 0 — same "no data, no penalty" rule as every other
     * optional KPI.
     */
    public function test_a_scope_with_no_data_is_left_out_of_the_average_not_scored_zero(): void
    {
        $leader = $this->clientHandler();
        $mine   = $this->clientHandler();
        $this->flowItems($leader, 10);
        $this->flowItems($mine, 5); // workflow-only: no clients for either

        $result = $this->volume($mine);

        $this->assertArrayHasKey('workflow', $result['scopes']);
        $this->assertArrayNotHasKey('client_handling', $result['scopes']);
        $this->assertSame(100.0, $result['pct']); // the workflow scope alone (nothing stalled), not averaged down by an absent scope
    }

    public function test_the_overall_score_is_the_average_of_every_applicable_scope(): void
    {
        $leader = $this->clientHandler();
        $mine   = $this->clientHandler();

        $this->flowItems($leader, 4);
        $this->flowItems($mine, 2); // nothing stalled -> 100% Result regardless of the 4-vs-2 split
        $this->clientsFor($leader, 4);
        $this->clientsFor($mine, 1); // 25%

        $result = $this->volume($mine);

        $this->assertSame(100.0, $result['scopes']['workflow']['pct']);
        $this->assertSame(25.0, $result['scopes']['client_handling']['pct']);
        $this->assertSame(62.5, $result['pct']); // (100 + 25) / 2
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
        $inFilter    = $this->clientHandler();
        $outOfFilter = $this->clientHandler(); // the real leader, outside the prefetch

        $this->flowItems($inFilter, 1);
        $this->flowItems($outOfFilter, 5);
        $this->clientsFor($inFilter, 1);
        $this->clientsFor($outOfFilter, 4);

        $calc = app(PerformanceCalculationService::class);
        $calc->prefetch(collect([$inFilter]), self::PERIOD); // simulates a department-filtered scoreboard run

        $result = $calc->outputVolume($inFilter, self::PERIOD);

        $this->assertSame(5.0, $result['scopes']['workflow']['cohort_max']);
        $this->assertSame(4.0, $result['scopes']['client_handling']['cohort_max']);
    }

    public function test_batch_scoring_matches_scoring_one_by_one(): void
    {
        $userA = $this->clientHandler();
        $userB = $this->clientHandler();
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
