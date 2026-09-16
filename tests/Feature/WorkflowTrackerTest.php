<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The workflow tracker: every item in one page, with the detail of any one.
 *
 * It answers "where is everything right now", so it defaults to what is still
 * running, and it opens to a read-only seat ("view workflows") as well as to
 * whoever builds the workflows — watching is not editing.
 */
class WorkflowTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->flow = app(FlowService::class);

        foreach (['view workflows', 'manage workflows', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo($permissions)->fresh();
    }

    private function client(string $name = 'ACME Ltd'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => $name,
            'brand_name' => 'ACME', 'category_id' => $category->id,
        ]);
    }

    /** @return array{0:Flow, 1:\App\Models\FlowStage, 2:\App\Models\FlowStage} */
    private function flowWith(User $worker): array
    {
        $admin  = $this->user('manage workflows');
        $flow   = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $brief  = $flow->stages()->create(['name' => 'Brief', 'position' => 1]);
        $review = $flow->stages()->create(['name' => 'Review', 'position' => 2]);
        $brief->users()->sync([$worker->id]);

        return [$flow->refresh(), $brief, $review];
    }

    private function item(Flow $flow, string $title, ?Client $client = null): FlowItem
    {
        return $this->flow->createItem($flow, array_filter([
            'title' => $title, 'client_id' => $client?->id,
        ]), $this->user('manage workflows'));
    }

    // ── Who may watch ────────────────────────────────────────────────────

    public function test_a_read_only_watcher_can_open_the_tracker(): void
    {
        $this->actingAs($this->user('view workflows'))
            ->get(route('workflows.items'))
            ->assertOk()
            ->assertSee('Workflow Tracker');
    }

    public function test_a_workflow_admin_can_open_it_too(): void
    {
        $this->actingAs($this->user('manage workflows'))->get(route('workflows.items'))->assertOk();
    }

    public function test_everybody_else_is_refused(): void
    {
        $nobody = $this->user('view clients');

        $this->actingAs($nobody)->get(route('workflows.items'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('workflows.items'), self::AJAX)->assertForbidden();
    }

    public function test_the_sidebar_offers_it_to_a_watcher(): void
    {
        $this->actingAs($this->user('view workflows'))
            ->get(route('dashboard'))
            ->assertSee(route('workflows.items'), false);
    }

    // ── What it lists ────────────────────────────────────────────────────

    public function test_it_shows_what_is_running_by_default(): void
    {
        $watcher = $this->user('view workflows');
        $worker  = $this->user();
        [$flow]  = $this->flowWith($worker);

        $this->item($flow, 'Still going');
        $cancelled = $this->item($flow, 'Withdrawn');
        $this->flow->cancelItem($cancelled->fresh(), $this->user('manage workflows'), 'not needed');

        $response = $this->actingAs($watcher)->getJson(route('workflows.items'), self::AJAX)->assertOk();

        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertStringContainsString('Still going', json_encode($response->json('data')));
        $this->assertStringNotContainsString('Withdrawn', json_encode($response->json('data')));

        // The counts still describe everything, so the other pills are usable.
        $response->assertJsonPath('counts.status.Open', 1)
            ->assertJsonPath('counts.status.Cancelled', 1)
            ->assertJsonPath('counts.total', 2);
    }

    public function test_it_can_show_everything_or_one_status(): void
    {
        $watcher = $this->user('view workflows');
        [$flow]  = $this->flowWith($this->user());
        $this->item($flow, 'Running one');
        $cancelled = $this->item($flow, 'Cancelled one');
        $this->flow->cancelItem($cancelled->fresh(), $this->user('manage workflows'));

        $this->actingAs($watcher)->getJson(route('workflows.items', ['status' => 'all']), self::AJAX)
            ->assertJsonPath('recordsFiltered', 2);

        $this->actingAs($watcher)->getJson(route('workflows.items', ['status' => 'Cancelled']), self::AJAX)
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_it_counts_what_needs_attention(): void
    {
        $watcher = $this->user('view workflows');
        $worker  = $this->user();
        [$flow, $brief] = $this->flowWith($worker);

        // Claimed and on time.
        $held = $this->item($flow, 'In hand');
        $this->flow->claim($held->fresh(), $worker);

        // Nobody has picked this up, and it is late.
        $late = $this->item($flow, 'Late one');
        $late->update(['due_date' => today()->subWeek()]);

        // Sitting at a stage with no people on it at all.
        $stranded = $this->item($flow, 'Stranded one');
        $stranded->update(['current_stage_id' => $flow->stages()->where('position', 2)->value('id')]);

        $this->actingAs($watcher)->getJson(route('workflows.items'), self::AJAX)
            ->assertJsonPath('counts.status.Open', 3)
            ->assertJsonPath('counts.unclaimed', 2)
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.stranded', 1);
    }

    public function test_it_narrows_by_workflow_stage_and_person(): void
    {
        $watcher = $this->user('view workflows');
        $worker  = $this->user();
        [$flow, $brief, $review] = $this->flowWith($worker);

        $mine = $this->item($flow, 'Claimed by the worker');
        $this->flow->claim($mine->fresh(), $worker);
        $this->item($flow, 'Nobody has it');

        $other = Flow::create(['name' => 'Other flow', 'is_active' => true, 'created_by' => $watcher->id]);
        $other->stages()->create(['name' => 'Only stage', 'position' => 1]);
        $this->item($other->refresh(), 'Somewhere else');

        $this->actingAs($watcher)->getJson(route('workflows.items', ['flow' => $flow->id]), self::AJAX)
            ->assertJsonPath('recordsFiltered', 2);

        $this->actingAs($watcher)->getJson(route('workflows.items', ['stage' => $brief->id]), self::AJAX)
            ->assertJsonPath('recordsFiltered', 2);

        $this->actingAs($watcher)->getJson(route('workflows.items', ['assigned_to' => $worker->id]), self::AJAX)
            ->assertJsonPath('recordsFiltered', 1);

        $this->actingAs($watcher)->getJson(route('workflows.items', ['unclaimed' => 1]), self::AJAX)
            ->assertJsonPath('recordsFiltered', 2);   // the unclaimed one in each flow
    }

    // ── The detail of one item ───────────────────────────────────────────

    public function test_the_details_panel_tells_the_whole_story_of_an_item(): void
    {
        $watcher = $this->user('view workflows');
        $worker  = $this->user();
        [$flow]  = $this->flowWith($worker);
        $client  = $this->client('ACME Ltd');

        $item = $this->item($flow, 'Logo design', $client);
        $this->flow->claim($item->fresh(), $worker);
        $this->flow->advance($item->fresh(), $worker, 'Off to review');

        $this->actingAs($watcher)
            ->getJson(route('workflows.items.details', $item))
            ->assertOk()
            ->assertJsonPath('title', 'Logo design')
            ->assertJsonPath('status', FlowItem::STATUS_OPEN)
            ->assertJsonPath('client.name', 'ACME Ltd')
            ->assertJsonPath('flow', 'Delivery')
            ->assertJsonPath('stages.0.name', 'Brief')
            ->assertJsonPath('stages.0.state', 'done')
            ->assertJsonPath('stages.1.name', 'Review')
            ->assertJsonPath('stages.1.state', 'current')
            ->assertJsonPath('history.0.to', 'Brief')
            ->assertJsonPath('history.1.from', 'Brief')
            ->assertJsonPath('history.1.to', 'Review')
            ->assertJsonPath('history.1.note', 'Off to review');
    }

    public function test_the_details_panel_is_refused_to_everybody_else(): void
    {
        [$flow] = $this->flowWith($this->user());
        $item   = $this->item($flow, 'Private work');

        $this->actingAs($this->user('view clients'))
            ->getJson(route('workflows.items.details', $item))
            ->assertForbidden();
    }

    /** A watcher may open the item page itself, but only to read it. */
    public function test_a_watcher_can_open_an_item_page_without_being_able_to_move_it(): void
    {
        $watcher = $this->user('view workflows');
        $worker  = $this->user();
        [$flow]  = $this->flowWith($worker);
        $item    = $this->item($flow, 'Readable');

        $this->actingAs($watcher)->get(route('flow-items.show', $item))->assertOk();

        $this->actingAs($watcher)
            ->postJson(route('flow-items.claim', $item))
            ->assertStatus(422);
    }
}
