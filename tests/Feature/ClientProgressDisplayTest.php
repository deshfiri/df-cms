<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The clients list page and a client's own profile page used to show two
 * different progress numbers for the same client: the list read
 * ClientProgressService (the Flow engine), the profile page read
 * Client::getProgressAttribute() — the old, retired WorkflowStage pipeline,
 * which nothing driving real client work writes to any more. Both now read
 * the same ClientProgressService.
 */
class ClientProgressDisplayTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->flow = app(FlowService::class);
        $this->allPermissions();
    }

    /** The client page asks about every module, and Spatie throws for a permission that doesn't exist. */
    private function allPermissions(): void
    {
        foreach ([
            'view clients', 'manage clients', 'create clients', 'delete clients', 'manage products', 'manage documents',
            'manage-workflow', 'submit-stage', 'approve-stage', 'import clients', 'export clients', 'manage users',
            'manage categories', 'view reports', 'view tasks', 'manage tasks', 'manage-meetings', 'view file-manager',
            'manage file-manager', 'view reviews', 'manage requests', 'view ads', 'manage ads', 'view performance',
            'manage performance', 'monitor chats', 'manage workflows', 'view whatsapp', 'reply whatsapp',
            'assign whatsapp', 'view all whatsapp', 'manage whatsapp numbers', 'manage whatsapp templates',
            'manage whatsapp settings',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function user(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view clients', 'manage clients', 'create clients', 'delete clients']);

        return $user->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    public function test_the_profile_page_shows_the_same_percentage_as_the_list_page(): void
    {
        $viewer = $this->user();
        $worker = $this->user();
        $client = $this->client();

        $flow = Flow::create(['name' => 'Onboarding', 'is_active' => true, 'created_by' => $viewer->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$worker->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'client_id' => $client->id], $viewer);
        $item = $this->flow->claim($item->fresh(), $worker);
        $this->flow->advance($item, $worker); // 1 of 2 stages done -> 50%

        $listRow = collect(
            $this->actingAs($viewer)
                ->getJson(route('clients.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->firstWhere('id', $client->id);
        $this->assertStringContainsString('50%', $listRow['progress']);

        $this->actingAs($viewer)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('50% complete')
            ->assertSee('(1/2')
            ->assertSee('stages)');
    }

    public function test_a_client_with_no_workflow_items_shows_0_percent_on_both_pages(): void
    {
        $viewer = $this->user();
        $client = $this->client();

        $listRow = collect(
            $this->actingAs($viewer)
                ->getJson(route('clients.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->firstWhere('id', $client->id);
        $this->assertStringContainsString('0%', $listRow['progress']);

        $this->actingAs($viewer)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('0% complete')
            ->assertSee('(0/0')
            ->assertSee('stages)');
    }
}
