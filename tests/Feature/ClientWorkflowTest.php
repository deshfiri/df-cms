<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The flow engine is now the client pipeline: the stage sequence an admin
 * builds under Workflows is what a client actually moves through.
 */
class ClientWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flow = app(FlowService::class);

        foreach (['manage workflows', 'view clients'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function client(): Client
    {
        $category = Category::create([
            'name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true,
        ]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    /** @return array{0:Flow, 1:array} */
    private function buildFlow(User $creator, array $stageNames, array $users): array
    {
        $flow = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $creator->id]);
        $stages = [];

        foreach ($stageNames as $i => $name) {
            $stage = $flow->stages()->create(['name' => $name, 'position' => $i + 1]);
            $stage->users()->sync(collect($users)->pluck('id'));
            $stages[] = $stage;
        }

        return [$flow->refresh(), $stages];
    }

    public function test_a_workflow_can_be_started_for_a_client(): void
    {
        $admin  = $this->user('manage workflows', 'view clients');
        $client = $this->client();
        [$flow] = $this->buildFlow($admin, ['Draft', 'Review'], [$admin]);

        $this->actingAs($admin)
            ->postJson(route('flow-items.store'), [
                'flow_id'   => $flow->id,
                'title'     => 'Onboarding',
                'client_id' => $client->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('flow_items', [
            'client_id' => $client->id,
            'flow_id'   => $flow->id,
            'title'     => 'Onboarding',
        ]);
    }

    public function test_an_item_without_a_client_still_works(): void
    {
        $admin = $this->user('manage workflows');
        [$flow] = $this->buildFlow($admin, ['Draft'], [$admin]);

        $this->actingAs($admin)
            ->postJson(route('flow-items.store'), ['flow_id' => $flow->id, 'title' => 'Internal task'])
            ->assertOk();

        $this->assertDatabaseHas('flow_items', ['title' => 'Internal task', 'client_id' => null]);
    }

    public function test_starting_a_workflow_for_a_client_needs_client_visibility(): void
    {
        // Can run workflows, but has no business seeing clients.
        $admin  = $this->user('manage workflows');
        $client = $this->client();
        [$flow] = $this->buildFlow($admin, ['Draft'], [$admin]);

        $this->actingAs($admin)
            ->postJson(route('flow-items.store'), [
                'flow_id'   => $flow->id,
                'title'     => 'Sneaky',
                'client_id' => $client->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('flow_items', 0);
    }

    public function test_the_client_workflow_endpoint_reports_the_whole_sequence(): void
    {
        $admin  = $this->user('manage workflows', 'view clients');
        $worker = $this->user('view clients');
        $client = $this->client();
        [$flow] = $this->buildFlow($admin, ['Draft', 'Review', 'Deliver'], [$admin, $worker]);

        $item = $this->flow->createItem($flow, [
            'title'     => 'Onboarding',
            'client_id' => $client->id,
        ], $admin);

        $this->flow->claim($item->fresh(), $admin);
        $this->flow->advance($item->fresh(), $admin);   // now at Review

        $payload = $this->actingAs($admin)
            ->getJson(route('clients.workflow', $client))
            ->assertOk()
            ->json('items.0');

        $this->assertSame('Onboarding', $payload['title']);
        $this->assertSame('Review', $payload['stage']);
        $this->assertCount(3, $payload['stages']);

        // Draft is behind us, Review is where we are, Deliver is ahead.
        $this->assertTrue($payload['stages'][0]['done']);
        $this->assertTrue($payload['stages'][1]['current']);
        $this->assertFalse($payload['stages'][2]['done']);
        $this->assertFalse($payload['stages'][2]['current']);
    }

    public function test_the_client_workflow_endpoint_is_closed_without_client_permission(): void
    {
        $this->actingAs($this->user())
            ->getJson(route('clients.workflow', $this->client()))
            ->assertForbidden();
    }

    public function test_one_clients_workflow_is_not_visible_on_another(): void
    {
        $admin = $this->user('manage workflows', 'view clients');
        $a     = $this->client();
        $b     = $this->client();
        [$flow] = $this->buildFlow($admin, ['Draft'], [$admin]);

        $this->flow->createItem($flow, ['title' => 'For A', 'client_id' => $a->id], $admin);

        $this->actingAs($admin)
            ->getJson(route('clients.workflow', $b))
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_a_client_can_run_more_than_one_workflow(): void
    {
        $admin  = $this->user('manage workflows', 'view clients');
        $client = $this->client();
        [$flow] = $this->buildFlow($admin, ['Draft'], [$admin]);

        $this->flow->createItem($flow, ['title' => 'Website', 'client_id' => $client->id], $admin);
        $this->flow->createItem($flow, ['title' => 'Branding', 'client_id' => $client->id], $admin);

        $this->actingAs($admin)
            ->getJson(route('clients.workflow', $client))
            ->assertOk()
            ->assertJsonCount(2, 'items');
    }

    public function test_deleting_a_client_leaves_the_item_rather_than_cascading(): void
    {
        $admin  = $this->user('manage workflows', 'view clients');
        $client = $this->client();
        [$flow] = $this->buildFlow($admin, ['Draft'], [$admin]);

        $item = $this->flow->createItem($flow, ['title' => 'Keep me', 'client_id' => $client->id], $admin);

        // Clients soft-delete, but a hard delete must not take the work history
        // with it — the FK is nullOnDelete.
        $client->forceDelete();

        $this->assertDatabaseHas('flow_items', ['id' => $item->id, 'client_id' => null]);
        $this->assertNotNull(FlowItem::find($item->id));
    }
}
