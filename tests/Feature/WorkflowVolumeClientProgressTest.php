<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\User;
use App\Services\FlowService;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Output Volume's Workflow Items scope is meant to read the same "how far
 * along is this client" story the clients list's own progress bar already
 * tells — 0%, partway, or finished (App\Services\ClientProgressService) —
 * not a raw count of FlowItem rows. A client can have several flow items
 * running at once; they all move the same needle, so a client-linked item
 * counts once per CLIENT, and only once that client has actually moved
 * (0% doesn't count yet). An item with no client attached still counts on
 * its own, same as before this existed.
 */
class WorkflowVolumeClientProgressTest extends TestCase
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

    /**
     * The client-progress needle this whole file exercises is a client-handling
     * concept, so these workers carry 'view clients' — see
     * WorkflowVolumeNoClientAccessTest for the same scenarios without it.
     */
    private function user(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view clients')->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    /** A flow with $stageCount stages, each claimable by $worker. */
    private function flowFor(User $worker, int $stageCount = 2): Flow
    {
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $this->user()->id]);
        foreach (range(1, $stageCount) as $position) {
            $flow->stages()->create(['name' => "Stage {$position}", 'position' => $position])->users()->sync([$worker->id]);
        }

        return $flow->refresh();
    }

    public function test_a_client_linked_item_sitting_at_its_first_stage_does_not_count_yet(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $flow   = $this->flowFor($worker, 3);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->user());
        $this->flow->claim($item->fresh(), $worker); // still sitting at stage 1 — 0% done

        // The item is tracked (it's due this period), but it hasn't moved the
        // client's needle at all yet, so it contributes nothing.
        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(0.0, $scope['mine']);
    }

    public function test_a_client_linked_item_counts_once_it_actually_moves(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $flow   = $this->flowFor($worker, 3);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->user());
        $item = $this->flow->claim($item->fresh(), $worker);
        $item = $this->flow->advance($item, $worker); // now at stage 2 — 1 of 3 stages done

        $this->assertGreaterThan(0, app(\App\Services\ClientProgressService::class)->percentFor($client->fresh()));

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    /** Several flow items for the same client are one client's worth of progress, not several. */
    public function test_several_items_for_the_same_client_count_once_not_per_item(): void
    {
        $worker = $this->user();
        $client = $this->client();

        foreach (range(1, 3) as $i) {
            $flow = $this->flowFor($worker, 2);
            $item = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->user());
            $item = $this->flow->claim($item->fresh(), $worker);
            $this->flow->advance($item, $worker); // each moves its own flow forward
        }

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine'], 'three flow items for one client are still just one client moved');
    }

    /** Two different people credited on the same client's work both get it — it isn't split between them. */
    public function test_two_people_credited_on_the_same_clients_work_both_get_it(): void
    {
        $client = $this->client();
        $first  = $this->user();
        $second = $this->user();

        $flow = Flow::create(['name' => 'Shared Client Flow', 'is_active' => true, 'created_by' => $this->user()->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$first->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$second->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->user());
        $item = $this->flow->claim($item->fresh(), $first);
        $item = $this->flow->advance($item, $first); // Draft -> Review, 1 of 2 done
        $this->flow->claim($item->fresh(), $second);

        foreach ([$first, $second] as $person) {
            $scope = $this->performance->outputVolume($person, '2026-09')['scopes']['workflow'];
            $this->assertSame(1.0, $scope['mine'], "{$person->id} touched this client's work and should be credited");
        }
    }

    /**
     * The scoreboard and the monthly snapshot command both compute every
     * employee from one shared, prefetched service instance rather than one
     * fresh instance per person — so the per-request client-qualifies cache
     * must give the same, correct answer whether several employees who
     * share a client are scored together in a batch or one at a time.
     */
    public function test_batch_scoring_matches_individual_scoring_when_people_share_a_client(): void
    {
        $client = $this->client();
        $first  = $this->user();
        $second = $this->user();

        $flow = Flow::create(['name' => 'Shared Client Flow', 'is_active' => true, 'created_by' => $this->user()->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$first->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$second->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->user());
        $item = $this->flow->claim($item->fresh(), $first);
        $item = $this->flow->advance($item, $first);
        $this->flow->claim($item->fresh(), $second);

        $individually = collect([$first, $second])
            ->map(fn (User $u) => app(PerformanceCalculationService::class)->outputVolume($u, '2026-09'))
            ->all();

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$first, $second]), '2026-09');
        $together = collect([$first, $second])->map(fn (User $u) => $batched->outputVolume($u, '2026-09'))->all();

        $this->assertEquals($individually, $together);
        $this->assertSame(1.0, $together[0]['scopes']['workflow']['mine']);
        $this->assertSame(1.0, $together[1]['scopes']['workflow']['mine']);
    }

    /** An internal item with no client still counts on its own, unaffected by any of this. */
    public function test_an_item_with_no_client_still_counts_on_its_own(): void
    {
        $worker = $this->user();
        $flow   = $this->flowFor($worker, 1);

        $item = $this->flow->createItem($flow, ['title' => 'Internal', 'due_date' => '2026-09-10'], $this->user());
        $this->flow->claim($item->fresh(), $worker);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    public function test_the_cohort_max_also_reflects_the_per_client_deduplication(): void
    {
        $client = $this->client();
        $leader = $this->user();
        $trailing = $this->user();

        // Leader touches three items for ONE client — still one client's worth.
        foreach (range(1, 3) as $i) {
            $flow = $this->flowFor($leader, 2);
            $item = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->user());
            $item = $this->flow->claim($item->fresh(), $leader);
            $this->flow->advance($item, $leader);
        }

        // Trailing touches one item for a different client.
        $otherClient = $this->client();
        $flow = $this->flowFor($trailing, 2);
        $item = $this->flow->createItem($flow, ['title' => 'Other', 'due_date' => '2026-09-11', 'client_id' => $otherClient->id], $this->user());
        $item = $this->flow->claim($item->fresh(), $trailing);
        $this->flow->advance($item, $trailing);

        $scope = $this->performance->outputVolume($trailing, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(1.0, $scope['cohort_max'], 'leader touched three items but only one distinct client, same as trailing');
        $this->assertSame(100.0, $scope['pct']);
    }
}
