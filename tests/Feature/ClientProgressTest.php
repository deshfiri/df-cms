<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Models\User;
use App\Services\ClientProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client progress is measured against the flows an admin built, now that the
 * flow engine is the pipeline. It used to be re-derived in three places from
 * the retired WorkflowStage tables — with formulas that disagreed.
 */
class ClientProgressTest extends TestCase
{
    use RefreshDatabase;

    private ClientProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->progress = app(ClientProgressService::class);
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    /** @return array{0:Flow,1:\Illuminate\Support\Collection<int,FlowStage>} */
    private function flow(int $stageCount): array
    {
        $creator = User::factory()->create(['is_active' => true]);
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $creator->id]);

        // range(1, 0) counts *down* to [1, 0] — not an empty flow.
        $stages = collect($stageCount > 0 ? range(1, $stageCount) : [])->map(
            fn ($i) => FlowStage::create(['flow_id' => $flow->id, 'name' => "Stage {$i}", 'position' => $i])
        );

        return [$flow, $stages];
    }

    private function item(Client $client, Flow $flow, ?FlowStage $stage, string $status = FlowItem::STATUS_OPEN): FlowItem
    {
        return FlowItem::create([
            'flow_id'          => $flow->id,
            'client_id'        => $client->id,
            'current_stage_id' => $stage?->id,
            'title'            => 'Work',
            'priority'         => 'Normal',
            'status'           => $status,
            'created_by'       => User::factory()->create(['is_active' => true])->id,
        ]);
    }

    public function test_a_client_with_no_work_is_at_zero(): void
    {
        $this->assertSame(0, $this->progress->percentFor($this->client()));
    }

    public function test_progress_is_the_stages_cleared_across_every_flow(): void
    {
        $client = $this->client();
        [$onboarding, $onboardingStages] = $this->flow(4);
        [$build, $buildStages] = $this->flow(6);

        // Sitting on stage 3 of 4 means two stages are behind it.
        $this->item($client, $onboarding, $onboardingStages[2]);
        $this->item($client, $build, $buildStages[0]);

        $breakdown = $this->progress->breakdownFor($client->fresh());

        $this->assertSame(2, $breakdown['done']);
        $this->assertSame(10, $breakdown['total']);
        $this->assertSame(20, $breakdown['percent']);
    }

    public function test_a_completed_item_counts_every_one_of_its_stages(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(5);

        $this->item($client, $flow, $stages[4], FlowItem::STATUS_COMPLETED);

        $this->assertSame(100, $this->progress->percentFor($client->fresh()));
    }

    public function test_an_item_on_the_first_stage_has_cleared_nothing(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(3);

        $this->item($client, $flow, $stages[0]);

        $this->assertSame(0, $this->progress->percentFor($client->fresh()));
    }

    public function test_a_cancelled_item_is_left_out_entirely(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(4);
        [$abandoned, $abandonedStages] = $this->flow(10);

        $this->item($client, $flow, $stages[3], FlowItem::STATUS_COMPLETED);
        $this->item($client, $abandoned, $abandonedStages[0], FlowItem::STATUS_CANCELLED);

        // Abandoned work must not hold the client below 100% forever.
        $this->assertSame(100, $this->progress->percentFor($client->fresh()));
    }

    public function test_progress_can_never_exceed_one_hundred(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(3);

        // Belt and braces: a completed item whose stage pointer was left beyond
        // the end of the flow used to be exactly how the old pipeline reported
        // more than 100%.
        $item = $this->item($client, $flow, $stages[2], FlowItem::STATUS_COMPLETED);
        $item->forceFill(['current_stage_id' => null])->save();

        $this->assertSame(100, $this->progress->percentFor($client->fresh()));
    }

    public function test_a_flow_with_no_stages_is_not_counted(): void
    {
        $client = $this->client();
        [$empty] = $this->flow(0);
        [$real, $realStages] = $this->flow(2);

        $this->item($client, $empty, null);
        $this->item($client, $real, $realStages[1]);

        $breakdown = $this->progress->breakdownFor($client->fresh());

        $this->assertSame(1, $breakdown['items']);
        $this->assertSame(2, $breakdown['total']);
        $this->assertSame(50, $breakdown['percent']);
    }

    public function test_another_clients_work_is_never_counted(): void
    {
        $mine   = $this->client();
        $theirs = $this->client();

        [$flow, $stages] = $this->flow(4);
        $this->item($theirs, $flow, $stages[3], FlowItem::STATUS_COMPLETED);

        $this->assertSame(0, $this->progress->percentFor($mine->fresh()));
    }

    public function test_the_eager_loaded_and_lazy_paths_agree(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(4);
        $this->item($client, $flow, $stages[2]);

        $lazy = $this->progress->percentFor(Client::findOrFail($client->id));

        $eager = $this->progress->percentFor(
            Client::with(['flowItems' => fn ($q) => $q->with(ClientProgressService::EAGER_LOAD)])
                ->findOrFail($client->id)
        );

        $this->assertSame($lazy, $eager);
        $this->assertSame(50, $eager);
    }
}
