<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Output Volume's "Workflow Items" scope for someone WITH client permission:
 * still primarily a comparison against the busiest person in the company —
 * carrying more raw volume than a smaller, fully-caught-up colleague still
 * earns a higher Result%. On top of that base ratio, each open item that's
 * been sitting untouched in this person's own hands for over a week docks
 * the result by a flat number of points — clearing your queue no longer
 * guarantees 100% by itself, it just means nothing gets subtracted.
 */
class WorkflowScopeWithClientAccessTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;
    private PerformanceCalculationService $performance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        $this->flow = app(FlowService::class);
        $this->performance = app(PerformanceCalculationService::class);
    }

    private function handler(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view clients')->fresh();
    }

    private function flowFor(User $worker, int $stageCount = 2): Flow
    {
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $this->handler()->id]);
        foreach (range(1, $stageCount) as $position) {
            $flow->stages()->create(['name' => "Stage {$position}", 'position' => $position])->users()->sync([$worker->id]);
        }

        return $flow->refresh();
    }

    private function backdate(FlowItem $item, int $daysAgo): void
    {
        FlowItem::where('id', $item->id)->update(['updated_at' => now()->subDays($daysAgo)]);
    }

    public function test_with_nothing_stalled_the_result_is_the_plain_ratio(): void
    {
        $leader = $this->handler();
        $mine = $this->handler();

        $flow = $this->flowFor($leader, 1);
        $this->flow->claim($this->flow->createItem($flow, ['title' => 'A', 'due_date' => '2026-09-10'], $leader), $leader);
        $flow2 = $this->flowFor($leader, 1);
        $this->flow->claim($this->flow->createItem($flow2, ['title' => 'B', 'due_date' => '2026-09-10'], $leader), $leader);

        $flow3 = $this->flowFor($mine, 1);
        $this->flow->claim($this->flow->createItem($flow3, ['title' => 'C', 'due_date' => '2026-09-10'], $mine), $mine);

        $scope = $this->performance->outputVolume($mine, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        $this->assertSame(50.0, $scope['pct']);
    }

    public function test_one_stalled_item_docks_a_flat_10_points_off_the_ratio(): void
    {
        $handler = $this->handler();
        $flow = $this->flowFor($handler, 1);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $handler);
        $item = $this->flow->claim($item->fresh(), $handler);
        $this->backdate($item, 10);

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];

        // They're their own cohort max (100% base), minus one stale item.
        $this->assertSame(90.0, $scope['pct']);
    }

    public function test_two_stalled_items_dock_twenty_points(): void
    {
        $handler = $this->handler();

        foreach (range(1, 2) as $i) {
            $flow = $this->flowFor($handler, 1);
            $item = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-10'], $handler);
            $item = $this->flow->claim($item->fresh(), $handler);
            $this->backdate($item, 10);
        }

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];
        $this->assertSame(80.0, $scope['pct']);
    }

    /** The penalty floors at 0 — it never wraps negative. */
    public function test_the_penalty_never_drops_the_result_below_zero(): void
    {
        $handler = $this->handler();

        foreach (range(1, 12) as $i) {
            $flow = $this->flowFor($handler, 1);
            $item = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-10'], $handler);
            $item = $this->flow->claim($item->fresh(), $handler);
            $this->backdate($item, 10);
        }

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];
        $this->assertSame(0.0, $scope['pct']);
    }

    /** An item held under a week isn't stale yet — no penalty. */
    public function test_an_item_held_under_a_week_is_not_penalised(): void
    {
        $handler = $this->handler();
        $flow = $this->flowFor($handler, 1);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $handler);
        $item = $this->flow->claim($item->fresh(), $handler);
        $this->backdate($item, 6);

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];
        $this->assertSame(100.0, $scope['pct']);
    }

    /**
     * The exact case this rule exists for: a busier person with a small
     * backlog should still be able to outscore a smaller, fully-caught-up
     * colleague — raw volume isn't wiped out by a modest penalty.
     */
    public function test_more_volume_with_a_small_backlog_still_outranks_less_volume_with_none(): void
    {
        $busy = $this->handler();
        foreach (range(1, 3) as $i) {
            $flow = $this->flowFor($busy, 1);
            $item = $this->flow->createItem($flow, ['title' => "Busy {$i}", 'due_date' => '2026-09-10'], $busy);
            $this->flow->claim($item->fresh(), $busy);
            // left open, uncompleted — these 3 count toward volume regardless
        }
        // One more item, held stale, on top of the 3 above.
        $staleFlow = $this->flowFor($busy, 1);
        $staleItem = $this->flow->createItem($staleFlow, ['title' => 'Stale', 'due_date' => '2026-09-11'], $busy);
        $staleItem = $this->flow->claim($staleItem->fresh(), $busy);
        $this->backdate($staleItem, 10);

        $quiet = $this->handler();
        $quietFlow = $this->flowFor($quiet, 1);
        $quietItem = $this->flow->createItem($quietFlow, ['title' => 'Quiet', 'due_date' => '2026-09-12'], $quiet);
        $this->flow->claim($quietItem->fresh(), $quiet);

        $busyScope = $this->performance->outputVolume($busy, '2026-09')['scopes']['workflow'];
        $quietScope = $this->performance->outputVolume($quiet, '2026-09')['scopes']['workflow'];

        // Busy: 4 items, sets the company max themselves (100% base) minus
        // 10 for the one stale item = 90%. Quiet: 1 item vs a max of 4 =
        // 25% base, nothing stale to dock.
        $this->assertSame(90.0, $busyScope['pct']);
        $this->assertSame(25.0, $quietScope['pct']);
        $this->assertGreaterThan($quietScope['pct'], $busyScope['pct']);
    }
}
