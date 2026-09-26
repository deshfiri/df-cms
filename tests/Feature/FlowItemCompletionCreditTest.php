<?php

namespace Tests\Feature;

use App\Models\DailyTarget;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A workflow item can pass through several stages, each worked by a
 * different person — every one of them earns their own full, independent
 * Workflow Items credit for it: the creator (their move into the first
 * stage), everyone who's ever claimed and moved it along at any stage, and
 * whoever currently holds it. Status decides nothing here any more —
 * Output Volume counts one still in progress the same as one already
 * finished, and being part of an item's history is enough regardless of
 * who happens to be holding it right now.
 *
 * These tests go through the real FlowService (claim/advance/sendBack),
 * unlike the synthetic FlowItem::create() fixtures elsewhere in this suite,
 * which don't reproduce a real transition history.
 */
class FlowItemCompletionCreditTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;
    private PerformanceCalculationService $performance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->flow = app(FlowService::class);
        $this->performance = app(PerformanceCalculationService::class);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /** Builds and completes a one-stage flow item end to end, the way real usage does it. */
    private function completeAnItemFor(User $worker, string $dueDate): FlowItem
    {
        $admin = $this->user();
        $flow  = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => $dueDate], $admin);
        $item = $this->flow->claim($item->fresh(), $worker);

        return $this->flow->advance($item, $worker);
    }

    public function test_a_genuinely_completed_item_is_credited_to_whoever_finished_it(): void
    {
        $worker = $this->user();
        $item   = $this->completeAnItemFor($worker, '2026-09-10');

        $this->assertSame(FlowItem::STATUS_COMPLETED, $item->status);
        $this->assertNull($item->assigned_to, 'sanity check: completion really does clear this');
        $this->assertSame($worker->id, $item->completed_by);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    public function test_the_cohort_leader_is_found_even_though_completion_clears_assigned_to(): void
    {
        $leader = $this->user();
        $trailing = $this->user();
        $this->completeAnItemFor($leader, '2026-09-10');
        $this->completeAnItemFor($leader, '2026-09-11');
        $this->completeAnItemFor($trailing, '2026-09-12');

        $scope = $this->performance->outputVolume($trailing, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        $this->assertSame(50.0, $scope['pct']);
    }

    public function test_the_cohort_max_also_counts_items_still_in_progress(): void
    {
        // A different, uninvolved creator per item, so the comparison below
        // is purely about who's currently carrying open items — the
        // creator's own credit is exercised separately elsewhere.
        $leader = $this->user();
        $trailing = $this->user();

        $itemA = $this->flow->createItem($this->oneStageFlow($leader)->refresh(), ['title' => 'A', 'due_date' => '2026-09-10'], $this->user());
        $this->flow->claim($itemA->fresh(), $leader);
        $itemB = $this->flow->createItem($this->oneStageFlow($leader)->refresh(), ['title' => 'B', 'due_date' => '2026-09-11'], $this->user());
        $this->flow->claim($itemB->fresh(), $leader);
        $itemC = $this->flow->createItem($this->oneStageFlow($trailing)->refresh(), ['title' => 'C', 'due_date' => '2026-09-12'], $this->user());
        $this->flow->claim($itemC->fresh(), $trailing);

        $scope = $this->performance->outputVolume($trailing, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        $this->assertSame(50.0, $scope['pct']);
    }

    /** A one-stage flow whose only stage $worker can claim, created by a throwaway admin. */
    private function oneStageFlow(User $worker): Flow
    {
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $this->user()->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        return $flow;
    }

    /**
     * The rule this whole change exists for: a manager who mostly creates
     * and delegates workflow items — never personally claiming most of
     * them — still earns Workflow Items credit for every one they created,
     * not just the ones they happen to be holding.
     */
    public function test_a_creator_who_never_personally_claims_it_is_still_credited(): void
    {
        $manager = $this->user();
        $worker  = $this->user();
        $flow = Flow::create(['name' => 'Delegated Work', 'is_active' => true, 'created_by' => $manager->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10'], $manager);
        $this->flow->claim($item->fresh(), $worker); // manager never claims it themselves

        $scope = $this->performance->outputVolume($manager, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    public function test_daily_targets_workflow_scope_also_counts_a_real_completion(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-20 12:00:00'));
        $worker = $this->user();
        DailyTarget::create(['user_id' => $worker->id, 'scope' => 'workflow', 'target_quantity' => 1]);
        $this->completeAnItemFor($worker, '2026-09-05');

        $scope = $this->performance->dailyTargetAchievement($worker, '2026-09')['scopes']['workflow'];

        $this->assertSame(1, $scope['available']);
        $this->assertSame(1, $scope['completed']);
    }

    public function test_prefetching_a_cohort_still_credits_a_completed_item(): void
    {
        $worker = $this->user();
        $item   = $this->completeAnItemFor($worker, '2026-09-10');

        $this->performance->prefetch([$worker], '2026-09');
        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
    }

    /**
     * The rule this whole change exists for: a workflow item can pass through
     * several stages, each worked by a different person, and every one of
     * them — the creator, everyone who claimed and moved it along, and
     * whoever finally finished it — earns their own full, independent
     * Workflow Items credit for it.
     */
    public function test_everyone_who_created_moved_or_finished_a_multi_stage_item_is_credited(): void
    {
        $creator = $this->user();
        $first   = $this->user();
        $second  = $this->user();
        $third   = $this->user();

        $flow = Flow::create(['name' => 'Onboarding', 'is_active' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$first->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$second->id]);
        $flow->stages()->create(['name' => 'Finalize', 'position' => 3])->users()->sync([$third->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10'], $creator);
        $item = $this->flow->claim($item->fresh(), $first);
        $item = $this->flow->advance($item, $first);
        $item = $this->flow->claim($item->fresh(), $second);
        $item = $this->flow->advance($item, $second);
        $item = $this->flow->claim($item->fresh(), $third);
        $item = $this->flow->advance($item, $third);

        $this->assertSame(FlowItem::STATUS_COMPLETED, $item->status);

        foreach ([$creator, $first, $second, $third] as $person) {
            $scope = $this->performance->outputVolume($person, '2026-09')['scopes']['workflow'];
            $this->assertSame(1.0, $scope['mine'], "{$person->id} should be credited for it");
        }
    }

    /** "next or previous stage" — sending it backward is still a move that earns credit. */
    public function test_sending_an_item_back_a_stage_still_earns_credit_once_it_later_completes(): void
    {
        $creator = $this->user();
        $first   = $this->user();
        $second  = $this->user();

        $flow = Flow::create(['name' => 'Review Flow', 'is_active' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$first->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$second->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10'], $creator);
        $item = $this->flow->claim($item->fresh(), $first);
        $item = $this->flow->advance($item, $first);           // Draft -> Review
        $item = $this->flow->claim($item->fresh(), $second);
        $item = $this->flow->sendBack($item, $second, 'Needs another pass'); // Review -> Draft
        $item = $this->flow->advance($item->fresh(), $first);  // Draft -> Review (auto-reclaimed by first)
        $item = $this->flow->claim($item->fresh(), $second);
        $item = $this->flow->advance($item, $second);          // Review -> done

        $this->assertSame(FlowItem::STATUS_COMPLETED, $item->status);

        // Second sent it back before ultimately finishing it — one credit, not two.
        $secondsScope = $this->performance->outputVolume($second, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $secondsScope['mine']);

        $firstsScope = $this->performance->outputVolume($first, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $firstsScope['mine']);
    }

    /** Crediting no longer depends on currently holding it — being part of its history is enough, whether or not it's claimed right now. */
    public function test_an_unclaimed_open_item_still_credits_everyone_who_touched_it_so_far(): void
    {
        $creator = $this->user();
        $first   = $this->user();
        $second  = $this->user();

        $flow = Flow::create(['name' => 'Still Open', 'is_active' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$first->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$second->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10'], $creator);
        $item = $this->flow->claim($item->fresh(), $first);
        $item = $this->flow->advance($item, $first); // now sits open at Review, unclaimed

        $this->assertSame(FlowItem::STATUS_OPEN, $item->status);
        $this->assertNull($item->assigned_to);

        foreach ([$creator, $first] as $person) {
            $scope = $this->performance->outputVolume($person, '2026-09')['scopes']['workflow'];
            $this->assertSame(1.0, $scope['mine'], "{$person->id} was part of it and should be credited regardless of who holds it now");
        }

        // Second hasn't been party to it at all yet.
        $secondsScope = $this->performance->outputVolume($second, '2026-09')['scopes']['workflow'] ?? null;
        $this->assertNull($secondsScope);
    }

    /**
     * Output Volume measures how much you're carrying, not just what you've
     * finished — an in-progress item counts fully for whoever currently
     * holds it, the same as a completed one, so it doesn't take finishing
     * something to show up here.
     */
    public function test_a_claimed_in_progress_item_counts_the_same_as_a_finished_one(): void
    {
        $creator = $this->user();
        $worker  = $this->user();

        $flow = Flow::create(['name' => 'In Progress', 'is_active' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10'], $creator);
        $item = $this->flow->claim($item->fresh(), $worker);

        $this->assertSame(FlowItem::STATUS_OPEN, $item->status);
        $this->assertSame($worker->id, $item->assigned_to);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    /**
     * A due_date is optional — plenty of workflow items are started without
     * one — so an item with none must still land in a period once it's
     * actually completed, by its completed_at, rather than never counting
     * for anyone at all.
     */
    public function test_an_item_with_no_due_date_is_credited_by_when_it_actually_completed(): void
    {
        $worker = $this->user();
        $admin  = $this->user();
        $flow   = Flow::create(['name' => 'No Due Date', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item'], $admin); // no due_date at all
        $this->assertNull($item->due_date);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-18 10:00:00'));
        $item = $this->flow->claim($item->fresh(), $worker);
        $item = $this->flow->advance($item, $worker);

        $this->assertSame(FlowItem::STATUS_COMPLETED, $item->status);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);

        // A different period than the one it actually finished in still sees nothing.
        $elsewhere = $this->performance->outputVolume($worker, '2026-08')['scopes']['workflow'] ?? null;
        $this->assertNull($elsewhere);
    }

    /** An open item with neither a due_date nor (not being done yet) a completed_at has nothing to anchor it to any period, so it doesn't count anywhere until one exists. */
    public function test_an_open_item_with_no_due_date_counts_in_no_period_yet(): void
    {
        $worker = $this->user();
        $admin  = $this->user();
        $flow   = Flow::create(['name' => 'Open No Due Date', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item'], $admin);
        $item = $this->flow->claim($item->fresh(), $worker);

        $this->assertSame(FlowItem::STATUS_OPEN, $item->status);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'] ?? null;
        $this->assertNull($scope);
    }
}
