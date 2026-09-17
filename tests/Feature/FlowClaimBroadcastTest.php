<?php

namespace Tests\Feature;

use App\Events\FlowItemClaimed;
use App\Exceptions\FlowException;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Claiming a workflow item: only one person can win, and everyone else looking
 * at it is told who did.
 */
class FlowClaimBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flows;
    private User $ali;
    private User $bina;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['manage workflows', 'view workflows'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->flows = app(FlowService::class);
        $this->ali   = User::factory()->create(['is_active' => true, 'name' => 'Ali']);
        $this->bina  = User::factory()->create(['is_active' => true, 'name' => 'Bina']);
    }

    private function item(): FlowItem
    {
        $admin = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage workflows');
        $flow  = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Design', 'position' => 1])->users()->sync([$this->ali->id, $this->bina->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$admin->id]);

        return $this->flows->createItem($flow->refresh(), ['title' => 'Logo'], $admin);
    }

    public function test_claiming_announces_who_took_it_to_the_items_channel(): void
    {
        Event::fake([FlowItemClaimed::class]);
        $item = $this->item();

        $this->actingAs($this->ali)->postJson(route('flow-items.claim', $item))->assertOk();

        Event::assertDispatched(FlowItemClaimed::class, function (FlowItemClaimed $e) use ($item) {
            $payload = $e->broadcastWith();

            return $e->broadcastOn()[0]->name === 'private-flow-item.' . $item->id
                && $e->broadcastAs() === 'item.claimed'
                && $payload['claimed_by'] === ['id' => $this->ali->id, 'name' => 'Ali']
                && $payload['queue_url'] === route('flow.queue');
        });
    }

    public function test_only_one_of_two_simultaneous_claims_wins(): void
    {
        Event::fake([FlowItemClaimed::class]);
        $item = $this->item();
        $binasView = $item->fresh();   // Bina opened it before Ali claimed

        $this->flows->claim($item->fresh(), $this->ali);

        try {
            $this->flows->claim($binasView, $this->bina);
            $this->fail('Both claims went through.');
        } catch (FlowException $e) {
            $this->assertSame('Ali claimed this item a moment ago.', $e->getMessage());
        }

        $this->assertSame($this->ali->id, $item->fresh()->assigned_to);
        Event::assertDispatchedTimes(FlowItemClaimed::class, 1);
    }

    public function test_a_websocket_failure_never_undoes_the_claim(): void
    {
        $item = $this->item();
        Event::listen(FlowItemClaimed::class, fn () => throw new \RuntimeException('Reverb is down'));

        $this->actingAs($this->ali)->postJson(route('flow-items.claim', $item))->assertOk();

        $this->assertSame($this->ali->id, $item->fresh()->assigned_to);
    }

    public function test_the_item_page_listens_and_sends_workers_back_to_their_queue(): void
    {
        $item = $this->item();

        $this->actingAs($this->bina)->get(route('flow-items.show', $item))
            ->assertOk()
            ->assertSee("'flow-item.' + ITEM", false)
            ->assertSee('.item.claimed', false)
            ->assertSee('const sendToQueue = true', false);

        $watcher = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view workflows');
        $this->actingAs($watcher)->get(route('flow-items.show', $item))
            ->assertOk()
            ->assertSee('const sendToQueue = false', false);
    }

    public function test_only_people_who_may_view_the_item_can_listen(): void
    {
        $item = $this->item();
        $outsider = User::factory()->create(['is_active' => true]);

        $channels = app(\Illuminate\Contracts\Broadcasting\Broadcaster::class)->getChannels();
        $authorize = $channels['flow-item.{itemId}'];

        $this->assertTrue((bool) $authorize($this->bina, $item->id));
        $this->assertFalse((bool) $authorize($outsider, $item->id));
        $this->assertFalse((bool) $authorize($this->bina, 999999));
    }
}
