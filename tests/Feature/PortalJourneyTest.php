<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Models\User;
use App\Services\ClientProgressService;
use App\Services\Portal\PortalJourneyPresenter;
use App\Services\Portal\PortalServiceGroupingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client portal journey reads the flow engine now. It used to read the
 * retired WorkflowStage pipeline, which nothing writes to any more — so every
 * client saw the same seeded stages sitting at "Pending" forever, and the
 * portal's headline percentage disagreed with the one staff saw.
 */
class PortalJourneyTest extends TestCase
{
    use RefreshDatabase;

    private PortalJourneyPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(PortalJourneyPresenter::class);
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
    private function flow(int $stageCount, bool $clientVisible = true, string $name = 'Website Build'): array
    {
        $creator = User::factory()->create(['is_active' => true]);

        $flow = Flow::create([
            'name'           => $name,
            'is_active'      => true,
            'client_visible' => $clientVisible,
            'created_by'     => $creator->id,
        ]);

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
            'title'            => 'Build the site',
            'priority'         => 'Normal',
            'status'           => $status,
            'created_by'       => User::factory()->create(['is_active' => true])->id,
        ]);
    }

    public function test_a_clients_flow_stages_become_their_journey(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(3);
        $this->item($client, $flow, $stages[1]);

        $journey = $this->presenter->present($client);

        $this->assertCount(3, $journey);
        $this->assertSame(['Approved', 'In Progress', 'Pending'], array_column($journey, 'status'));
        $this->assertSame([false, true, false], array_column($journey, 'current'));
        // Work cannot skip ahead, so only what is still out of reach is locked.
        $this->assertSame([false, false, true], array_column($journey, 'locked'));
    }

    public function test_an_internal_flow_is_never_shown_to_the_client(): void
    {
        $client = $this->client();
        [$internal, $internalStages] = $this->flow(4, clientVisible: false, name: 'Internal QA');
        $this->item($client, $internal, $internalStages[0]);

        $this->assertSame([], $this->presenter->present($client));
        $this->assertSame([], app(PortalServiceGroupingService::class)->groupByDepartment($client));
    }

    public function test_a_cancelled_item_drops_out_of_the_journey(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(3);
        $this->item($client, $flow, $stages[0], FlowItem::STATUS_CANCELLED);

        $this->assertSame([], $this->presenter->present($client));
    }

    public function test_a_completed_item_shows_every_stage_as_done(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(3);
        $this->item($client, $flow, $stages[2], FlowItem::STATUS_COMPLETED);

        $journey = $this->presenter->present($client);

        $this->assertSame(['Approved', 'Approved', 'Approved'], array_column($journey, 'status'));
        $this->assertSame([false, false, false], array_column($journey, 'current'));
    }

    public function test_the_portal_percentage_matches_what_staff_see(): void
    {
        $client = $this->client();
        [$flow, $stages] = $this->flow(4);
        $this->item($client, $flow, $stages[2]);

        // The number a client is told must be the number the team is looking at.
        $this->assertSame(
            app(ClientProgressService::class)->percentFor($client->fresh()),
            $this->presenter->overallProgressPercent($client)
        );
        $this->assertSame(50, $this->presenter->overallProgressPercent($client));
    }

    public function test_each_piece_of_work_becomes_its_own_service_card(): void
    {
        $client = $this->client();
        [$site, $siteStages]   = $this->flow(4, name: 'Website Build');
        [$brand, $brandStages] = $this->flow(2, name: 'Branding');

        $this->item($client, $site, $siteStages[2]);
        $this->item($client, $brand, $brandStages[1], FlowItem::STATUS_COMPLETED);

        $services = app(PortalServiceGroupingService::class)->groupByDepartment($client);

        $this->assertCount(2, $services);
        $this->assertSame(['Website Build', 'Branding'], array_column($services, 'department'));
        $this->assertSame(['Active', 'Completed'], array_column($services, 'status'));
        $this->assertSame([50, 100], array_column($services, 'progress'));
    }

    public function test_the_journey_says_which_work_each_stage_belongs_to(): void
    {
        $client = $this->client();
        [$site, $siteStages] = $this->flow(2, name: 'Website Build');
        $this->item($client, $site, $siteStages[0]);

        $journey = $this->presenter->present($client);

        // Without this the client sees two flows merged into one baffling list.
        $this->assertSame('Website Build', $journey[0]['service']);
        $this->assertNotNull($journey[0]['item_id']);
    }

    public function test_another_clients_work_never_appears(): void
    {
        $mine   = $this->client();
        $theirs = $this->client();

        [$flow, $stages] = $this->flow(3);
        $this->item($theirs, $flow, $stages[1]);

        $this->assertSame([], $this->presenter->present($mine));
        $this->assertSame(0, $this->presenter->overallProgressPercent($mine));
    }

    public function test_a_client_with_no_work_gets_an_empty_journey(): void
    {
        $client = $this->client();

        $this->assertSame([], $this->presenter->present($client));
        $this->assertNull($this->presenter->currentStage($client));
        $this->assertSame(0, $this->presenter->overallProgressPercent($client));
    }
}
