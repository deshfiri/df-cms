<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
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
 * Output Volume's company-wide "who's carrying the most" comparison is a
 * client-handling concept a worker without client permission never sees or
 * manages, so their "Workflow Items" scope drops it entirely — no comparison
 * to anyone, company or peer. "Theirs" is simply a point per qualifying claim
 * or stage transition credited to them this period (never zero just because
 * they've since forwarded everything along), and the Result % asks a
 * separate question: is their own queue moving right now? 100% unless
 * they're currently sitting on an open item, still in their hands, that
 * they haven't forwarded or sent back for over a week (the same "delayed"
 * threshold the dashboard pipeline uses). The Client Handling scope never
 * applies to them regardless of any stray `assigned_to` row.
 */
class WorkflowVolumeNoClientAccessTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;
    private PerformanceCalculationService $performance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage clients', 'guard_name' => 'web']);
        $this->flow = app(FlowService::class);
        $this->performance = app(PerformanceCalculationService::class);
    }

    /** A user with no permissions at all — a stage-only worker, not a client handler. */
    private function worker(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function actor(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage clients')->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    private function flowFor(User $worker, int $stageCount = 2): Flow
    {
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $this->actor()->id]);
        foreach (range(1, $stageCount) as $position) {
            $flow->stages()->create(['name' => "Stage {$position}", 'position' => $position])->users()->sync([$worker->id]);
        }

        return $flow->refresh();
    }

    /** Backdates a held item's updated_at without touching Eloquent's own auto-touch. */
    private function backdate(FlowItem $item, int $daysAgo): void
    {
        FlowItem::where('id', $item->id)->update(['updated_at' => now()->subDays($daysAgo)]);
    }

    public function test_a_freshly_claimed_item_does_not_count_as_stalled(): void
    {
        $worker = $this->worker();
        $client = $this->client();
        $flow   = $this->flowFor($worker, 3);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->actor());
        $this->flow->claim($item->fresh(), $worker); // just claimed — still at stage 1, but only seconds old

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(100.0, $scope['pct'], 'holding something you just picked up is not the same as sitting on it');
    }

    /** The rule this whole change exists for: the client's own progress plays no part in it any more. */
    public function test_an_item_held_untouched_for_over_a_week_drags_the_score_down(): void
    {
        $worker = $this->worker();
        $flow   = $this->flowFor($worker, 2);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $this->actor());
        $item = $this->flow->claim($item->fresh(), $worker);
        $this->backdate($item, 8);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];

        // "Theirs" still shows the real activity credited to them this
        // period — never zero just because it's currently stuck — while the
        // Result % is independently dragged down by the stale claim.
        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(1.0, $scope['cohort_max']);
        $this->assertSame(0.0, $scope['pct']);
    }

    /** Comfortably under a week old isn't stale yet — the threshold is "over" a week. */
    public function test_an_item_held_for_under_a_week_is_not_yet_stalled(): void
    {
        $worker = $this->worker();
        $flow   = $this->flowFor($worker, 2);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $this->actor());
        $item = $this->flow->claim($item->fresh(), $worker);
        $this->backdate($item, 6);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(100.0, $scope['pct']);
    }

    /** Only the stalled items pull the score down — a fresh one held alongside doesn't get penalised too. */
    public function test_only_the_stalled_item_counts_against_them_not_a_fresh_one_held_alongside(): void
    {
        $worker = $this->worker();
        $flow   = $this->flowFor($worker, 2);

        $stale = $this->flow->createItem($flow, ['title' => 'Stale', 'due_date' => '2026-09-10'], $this->actor());
        $stale = $this->flow->claim($stale->fresh(), $worker);
        $this->backdate($stale, 10);

        $fresh = $this->flow->createItem($flow, ['title' => 'Fresh', 'due_date' => '2026-09-11'], $this->actor());
        $this->flow->claim($fresh->fresh(), $worker);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];

        // Both items count toward "Theirs" (real activity, whether stalled
        // or not) — only the Result % is dragged down by the one that is.
        $this->assertSame(2.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        $this->assertSame(50.0, $scope['pct']);
    }

    /**
     * Mirrors the reported case exactly: a worker who forwarded every item
     * they touched, holding nothing pending, should read 100% — the client's
     * own progress bar (even stuck at 0%) plays no part in it any more.
     */
    public function test_forwarding_everything_along_leaves_nothing_held_and_scores_100(): void
    {
        $worker = $this->worker();

        foreach (range(1, 3) as $i) {
            $client = $this->client();
            $flow   = $this->flowFor($worker, 2);
            $item   = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->actor());
            $item   = $this->flow->claim($item->fresh(), $worker);
            $this->flow->advance($item, $worker); // forwarded — nothing left in their hands
        }

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];

        // All 3 touched clients still show up as real, credited activity —
        // never 0 just because none of it is still sitting in their hands.
        $this->assertSame(3.0, $scope['mine']);
        $this->assertSame(3.0, $scope['cohort_max']);
        $this->assertSame(100.0, $scope['pct'], 'nothing currently held means nothing left untouched');
    }

    /**
     * The bug this test guards against: two items for the SAME client must
     * count as two points, not one — that per-client deduplication is a
     * client-handling concept (workflowVolumeCount()) that never applies
     * here. Simple logic: every claim-and-move earns its own point.
     */
    public function test_two_items_for_the_same_client_count_as_two_points_not_one(): void
    {
        $worker = $this->worker();
        $client = $this->client();

        $first = $this->flow->createItem($this->flowFor($worker, 2), ['title' => 'First', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->actor());
        $first = $this->flow->claim($first->fresh(), $worker);
        $this->flow->advance($first, $worker);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);

        // The same worker then claims and moves a SECOND item for the exact
        // same client — this must push the count to 2, not leave it at 1.
        $second = $this->flow->createItem($this->flowFor($worker, 2), ['title' => 'Second', 'due_date' => '2026-09-11', 'client_id' => $client->id], $this->actor());
        $second = $this->flow->claim($second->fresh(), $worker);
        $this->flow->advance($second, $worker);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(2.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        $this->assertSame(100.0, $scope['pct']);
    }

    /** A completed item isn't "pending" — it doesn't linger in the held count at all. */
    public function test_a_completed_item_does_not_count_as_held(): void
    {
        $worker = $this->worker();
        $flow   = $this->flowFor($worker, 1);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $this->actor());
        $item = $this->flow->claim($item->fresh(), $worker);
        $this->flow->advance($item, $worker); // only stage — this completes it

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(100.0, $scope['pct']);
    }

    /**
     * This whole "no comparison" rule is specific to someone without client
     * access — a client handler is still compared to the company's busiest
     * person for the bulk of their Result%. They're not entirely exempt from
     * a stale item, though: see WorkflowScopeWithClientAccessTest for the
     * separate point-penalty that applies to them instead.
     */
    public function test_someone_with_client_access_is_still_scored_against_the_company_not_this_rule(): void
    {
        $handler = tap($this->worker())->givePermissionTo('view clients')->fresh();
        $flow = $this->flowFor($handler, 2);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10'], $this->actor());
        $item = $this->flow->claim($item->fresh(), $handler);
        $this->backdate($item, 30); // sitting untouched a long time

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];

        // The company-wide comparison formula: they're the only toucher, so
        // they're their own cohort max — 100% before any penalty. This is the
        // pre-existing, unrelated formula, not the one this test class
        // covers; the docked 10 points for the one stale item is
        // workflowScopeWithClientAccess()'s own, separate rule.
        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(1.0, $scope['cohort_max']);
        $this->assertSame(90.0, $scope['pct']);
    }

    public function test_client_handling_scope_never_applies_to_a_user_without_client_access(): void
    {
        $worker = $this->worker();
        $client = $this->client();
        // A stray assignment (e.g. left over from before a role change) — never
        // legitimately reachable through ClientPolicy, but nothing stops the
        // column itself from holding it.
        $client->forceFill(['assigned_to' => $worker->id])->save();

        // Nothing else to show in Output Volume either, so with the
        // client_handling scope correctly excluded there is nothing left at all.
        $this->assertNull($this->performance->outputVolume($worker, '2026-09'));

        // Give them something unrelated to show, and confirm client_handling
        // still never appears alongside it.
        $flow = $this->flowFor($worker, 1);
        $item = $this->flow->createItem($flow, ['title' => 'Internal', 'due_date' => '2026-09-10'], $this->actor());
        $this->flow->claim($item->fresh(), $worker);

        $result = $this->performance->outputVolume($worker, '2026-09');

        $this->assertArrayHasKey('workflow', $result['scopes']);
        $this->assertArrayNotHasKey('client_handling', $result['scopes']);
    }

    /** A non-client-access worker's raw touch count never inflates the company max other client handlers are compared against. */
    public function test_a_worker_without_client_access_never_inflates_the_cohort_max_for_client_handlers(): void
    {
        $handler = tap($this->worker())->givePermissionTo('view clients')->fresh();
        $handlerItem = $this->flow->createItem($this->flowFor($handler, 2), ['title' => 'Handled', 'due_date' => '2026-09-10'], $this->actor());
        $this->flow->claim($handlerItem->fresh(), $handler);

        // A permission-less worker who touched five distinct clients this
        // period — under the old rule this would have set the company max
        // to 5; it must no longer count toward it at all.
        $worker = $this->worker();
        foreach (range(1, 5) as $i) {
            $client = $this->client();
            $flow   = $this->flowFor($worker, 2);
            $item   = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-11', 'client_id' => $client->id], $this->actor());
            $item   = $this->flow->claim($item->fresh(), $worker);
            $this->flow->advance($item, $worker);
        }

        $scope = $this->performance->outputVolume($handler, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['cohort_max'], 'only client-access peers should ever feed the company-wide comparison');
    }

    /** No comparison to anyone at all — a permission-less worker's own count is simply their own, never measured against a peer or the company. */
    public function test_no_one_elses_activity_affects_a_workers_own_count(): void
    {
        $quiet = $this->worker();
        $item = $this->flow->createItem($this->flowFor($quiet, 1), ['title' => 'Quiet', 'due_date' => '2026-09-10'], $this->actor());
        $item = $this->flow->claim($item->fresh(), $quiet);
        $this->flow->advance($item, $quiet); // 1 credited item, forwarded

        $busy = $this->worker();
        foreach (range(1, 4) as $i) {
            $flow = $this->flowFor($busy, 1);
            $busyItem = $this->flow->createItem($flow, ['title' => "Busy {$i}", 'due_date' => '2026-09-11'], $this->actor());
            $busyItem = $this->flow->claim($busyItem->fresh(), $busy);
            $this->flow->advance($busyItem, $busy);
        }

        $quietScope = $this->performance->outputVolume($quiet, '2026-09')['scopes']['workflow'];
        $busyScope = $this->performance->outputVolume($busy, '2026-09')['scopes']['workflow'];

        // Each worker's own count, matched by their own "cohort_max" (i.e. no
        // comparison at all) — the busy worker's 4 items never affect the
        // quiet worker's own tally or vice versa.
        $this->assertSame(1.0, $quietScope['mine']);
        $this->assertSame(1.0, $quietScope['cohort_max']);
        $this->assertSame(100.0, $quietScope['pct']);
        $this->assertSame(4.0, $busyScope['mine']);
        $this->assertSame(4.0, $busyScope['cohort_max']);
        $this->assertSame(100.0, $busyScope['pct']);
    }

    /**
     * No separate backfill job is needed for past months: every number here
     * is computed live from FlowItem/FlowTransition history, the same as any
     * other period, so a month closed long ago still reads correctly today.
     */
    public function test_a_past_periods_activity_is_still_counted_correctly_with_no_backfill_needed(): void
    {
        $worker = $this->worker();
        $flow = $this->flowFor($worker, 1);
        $item = $this->flow->createItem($flow, ['title' => 'Old Item', 'due_date' => '2026-06-10'], $this->actor());
        $item = $this->flow->claim($item->fresh(), $worker);
        $this->flow->advance($item, $worker);

        $scope = $this->performance->outputVolume($worker, '2026-06')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(100.0, $scope['pct']);

        // A different, uninvolved period sees nothing for it.
        $elsewhere = $this->performance->outputVolume($worker, '2026-07');
        $this->assertNull($elsewhere);
    }
}
