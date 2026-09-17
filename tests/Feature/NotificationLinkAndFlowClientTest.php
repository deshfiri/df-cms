<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\FlowItemAwaitingYou;
use App\Notifications\TaskAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Two fixes that were each about reading the right thing at the right moment.
 *
 * A task notification linked to the modal's JSON endpoint, so following it from
 * the bell showed a raw payload. And a workflow item started from the queue or
 * the workflow page carried no client, so nobody could tell whose work it was.
 */
class NotificationLinkAndFlowClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['view tasks', 'manage tasks', 'manage workflows', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
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

    private function client(string $name = 'ACME Ltd'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    private function flow(User $member): Flow
    {
        $flow = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $member->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$member->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$member->id]);

        return $flow->refresh();
    }

    // ── Task links from the notification bell ────────────────────────────

    /**
     * The reported bug. A browser following the link must land on a page, not
     * be handed the JSON the scripts consume.
     */
    public function test_following_a_task_link_in_a_browser_opens_the_task_page(): void
    {
        $worker = $this->user('view tasks');
        $task   = Task::create([
            'title' => 'Write the brief', 'priority' => 'Medium', 'status' => 'Pending',
            'type' => 'Other', 'assigned_to' => $worker->id, 'created_by' => $worker->id,
        ]);

        $this->actingAs($worker)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewIs('tasks.show')
            ->assertSee('Write the brief');
    }

    /** Links stored before tasks had a page (/tasks?task=12) still arrive at the task. */
    public function test_an_old_list_link_redirects_to_the_task_page(): void
    {
        $worker = $this->user('view tasks');
        $task   = Task::create([
            'title' => 'Write the brief', 'priority' => 'Medium', 'status' => 'Pending',
            'type' => 'Other', 'assigned_to' => $worker->id, 'created_by' => $worker->id,
        ]);

        $this->actingAs($worker)
            ->get(route('tasks.index', ['task' => $task->id]))
            ->assertRedirect(route('tasks.show', $task->id));
    }

    /** Notifications already in the database carry the old URL; they must keep working. */
    public function test_the_page_itself_still_gets_json(): void
    {
        $worker = $this->user('view tasks');
        $task   = Task::create([
            'title' => 'Write the brief', 'priority' => 'Medium', 'status' => 'Pending',
            'type' => 'Other', 'assigned_to' => $worker->id, 'created_by' => $worker->id,
        ]);

        $this->actingAs($worker)
            ->getJson(route('tasks.show', $task), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('task.id', $task->id);
    }

    /** A redirect must not confirm that a task you may not see exists. */
    public function test_an_unauthorised_browser_visit_is_refused_not_redirected(): void
    {
        $owner    = $this->user('view tasks');
        $stranger = $this->user('view tasks');
        $task     = Task::create([
            'title' => 'Private', 'priority' => 'Medium', 'status' => 'Pending',
            'type' => 'Other', 'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);

        $this->actingAs($stranger)->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_new_task_notifications_link_to_the_page(): void
    {
        $task = Task::create([
            'title' => 'Write the brief', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
        ]);

        $payload = (new TaskAssigned($task))->toDatabase(new User());

        $this->assertSame(route('tasks.show', $task->id), $payload['url']);
    }

    // ── Workflow items know their client ─────────────────────────────────

    public function test_an_item_started_from_the_queue_can_carry_a_client(): void
    {
        $member = $this->user('manage workflows', 'view clients');
        $client = $this->client();
        $flow   = $this->flow($member);

        $this->actingAs($member)->postJson(route('flow-items.store'), [
            'flow_id'   => $flow->id,
            'title'     => 'Logo design',
            'client_id' => $client->id,
        ])->assertOk();

        $this->assertSame($client->id, FlowItem::firstOrFail()->client_id);
    }

    public function test_an_item_can_still_be_internal(): void
    {
        $member = $this->user('manage workflows', 'view clients');
        $flow   = $this->flow($member);

        $this->actingAs($member)->postJson(route('flow-items.store'), [
            'flow_id' => $flow->id,
            'title'   => 'Office move',
        ])->assertOk();

        $this->assertNull(FlowItem::firstOrFail()->client_id);
    }

    public function test_the_queue_shows_whose_work_it_is(): void
    {
        $member = $this->user('manage workflows', 'view clients');
        $client = $this->client('Karima World Shop');
        $flow   = $this->flow($member);

        $this->actingAs($member)->postJson(route('flow-items.store'), [
            'flow_id' => $flow->id, 'title' => 'Logo design', 'client_id' => $client->id,
        ])->assertOk();

        $this->actingAs($member)
            ->get(route('flow.queue'))
            ->assertOk()
            ->assertSee('Karima World Shop');
    }

    public function test_the_item_page_shows_the_client(): void
    {
        $member = $this->user('manage workflows', 'view clients');
        $client = $this->client('Karima World Shop');
        $flow   = $this->flow($member);

        $this->actingAs($member)->postJson(route('flow-items.store'), [
            'flow_id' => $flow->id, 'title' => 'Logo design', 'client_id' => $client->id,
        ])->assertOk();

        $this->actingAs($member)
            ->get(route('flow-items.show', FlowItem::firstOrFail()))
            ->assertOk()
            ->assertSee('Karima World Shop');
    }

    public function test_the_start_form_offers_the_client_picker(): void
    {
        $member = $this->user('manage workflows', 'view clients');
        $this->client('Karima World Shop');
        $flow = $this->flow($member);

        $this->actingAs($member)->get(route('workflows.show', $flow))
            ->assertOk()
            ->assertSee('itemClient', false)
            ->assertSee('Karima World Shop');
    }

    public function test_a_notification_names_the_client(): void
    {
        $member = $this->user('manage workflows');
        $client = $this->client('Karima World Shop');
        $flow   = $this->flow($member);

        $item = FlowItem::create([
            'flow_id' => $flow->id, 'client_id' => $client->id,
            'current_stage_id' => $flow->stages->first()->id,
            'title' => 'Logo design', 'status' => FlowItem::STATUS_OPEN, 'created_by' => $member->id,
        ]);

        $payload = (new FlowItemAwaitingYou($item, $flow->stages->first(), ''))->toArray($member);

        $this->assertStringContainsString('"Logo design" for Karima World Shop', $payload['message']);
    }
}
