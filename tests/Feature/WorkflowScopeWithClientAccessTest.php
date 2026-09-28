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
 * Result% is a pure "cleared your queue" compliance check — 100% unless an
 * open item has been sitting untouched in this person's own hands for over
 * a week, in which case each such item docks the result by a flat number of
 * points. It is never reduced just because someone else moved more items
 * that period; `mine`/`cohort_max` are still tracked and returned purely as
 * informational figures.
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

    public function test_with_nothing_stalled_the_result_is_100_regardless_of_how_it_compares(): void
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

        // Theirs/company's highest are still tracked and shown...
        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        // ...but with nothing stalled, Result is 100% even though someone
        // else moved more items that period.
        $this->assertSame(100.0, $scope['pct']);
    }

    public function test_one_stalled_item_docks_a_flat_10_points(): void
    {
        $handler = $this->handler();
        $flow = $this->flowFor($handler, 1);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $handler);
        $item = $this->flow->claim($item->fresh(), $handler);
        $this->backdate($item, 10);

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];

        // 100% base minus one stale item.
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
     * The exact case this rule exists for: someone with fewer items than a
     * busier colleague, but nothing currently stuck in their own hands,
     * must not be marked down just for having done less that period — a
     * quieter, fully-caught-up colleague scores just as well on Result as
     * a busier one, even though the busier one is visibly carrying more
     * (Theirs / Company's highest stay honest about that, separately).
     */
    public function test_fewer_items_than_a_busier_colleague_does_not_reduce_result_when_nothing_is_stalled(): void
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

        // Busy: 4 items (visibly the company's highest that period), 100%
        // base minus 10 for the one stale item = 90%.
        $this->assertSame(4.0, $busyScope['mine']);
        $this->assertSame(90.0, $busyScope['pct']);

        // Quiet: only 1 item, nowhere near the company's highest of 4 — but
        // nothing of theirs is stalled, so Result is still 100%, not docked
        // for having done less than Busy.
        $this->assertSame(1.0, $quietScope['mine']);
        $this->assertSame(4.0, $quietScope['cohort_max']);
        $this->assertSame(100.0, $quietScope['pct']);
    }
}
