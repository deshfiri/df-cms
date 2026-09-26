<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Notifications\FlowItemCommentMention;
use App\Notifications\FlowItemNewComment;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A workflow's discussion is open — unlike a task's, anyone active in the
 * system can be @mentioned, not just whoever's already party to the item.
 * Only those actually named get notified.
 */
class FlowItemCommentMentionTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->flow = app(FlowService::class);
        Permission::firstOrCreate(['name' => 'manage workflows', 'guard_name' => 'web']);
    }

    private function user(string $name = 'User'): User
    {
        return User::factory()->create(['name' => $name, 'is_active' => true]);
    }

    private function itemFor(User $worker): FlowItem
    {
        $admin = tap($this->user('Admin'))->givePermissionTo('manage workflows');
        $flow  = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Brief', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Logo'], $admin);

        return $this->flow->claim($item->fresh(), $worker);
    }

    public function test_mentioning_someone_with_no_connection_to_the_item_still_notifies_them(): void
    {
        $worker  = $this->user('Worker');
        $item    = $this->itemFor($worker);
        $outsider = $this->user('Faraway Outsider'); // not a stage member, not the creator

        $this->actingAs($worker)->postJson(route('flow-items.comments.store', $item), [
            'body' => 'Hey @Faraway Outsider, thoughts on this?',
        ])->assertOk();

        Notification::assertSentTo($outsider, FlowItemCommentMention::class);
    }

    public function test_mentioning_yourself_does_not_notify_you(): void
    {
        $worker = $this->user('Worker');
        $item   = $this->itemFor($worker);

        $this->actingAs($worker)->postJson(route('flow-items.comments.store', $item), [
            'body' => 'Note to self, @Worker',
        ])->assertOk();

        Notification::assertNotSentTo($worker, FlowItemCommentMention::class);
    }

    public function test_a_comment_with_no_mention_sends_no_mention_notification(): void
    {
        $worker = $this->user('Worker');
        $item   = $this->itemFor($worker);
        $outsider = $this->user('Faraway Outsider');

        $this->actingAs($worker)->postJson(route('flow-items.comments.store', $item), [
            'body' => 'Just an update, nothing else.',
        ])->assertOk();

        Notification::assertNothingSentTo($outsider);
    }

    /** The two notifications are independent — a mention doesn't replace the existing participant broadcast. */
    public function test_a_mention_notification_is_additional_to_the_normal_comment_notification(): void
    {
        $worker  = $this->user('Worker');
        $item    = $this->itemFor($worker);
        $outsider = $this->user('Faraway Outsider');

        $this->actingAs($worker)->postJson(route('flow-items.comments.store', $item), [
            'body' => '@Faraway Outsider please weigh in',
        ])->assertOk();

        Notification::assertSentTo($outsider, FlowItemCommentMention::class);
        // The item's own creator (an admin, not mentioned) still gets the normal broadcast.
        Notification::assertSentTo($item->creator, FlowItemNewComment::class);
    }

    public function test_the_comment_renders_the_mention_highlighted(): void
    {
        $worker = $this->user('Worker');
        $item   = $this->itemFor($worker);
        $this->user('Faraway Outsider');

        $this->actingAs($worker)->postJson(route('flow-items.comments.store', $item), [
            'body' => '@Faraway Outsider please take a look',
        ])->assertOk();

        $response = $this->actingAs($worker)->get(route('flow-items.show', $item));

        $response->assertOk()->assertSee('class="mention"', false)->assertSee('@Faraway Outsider');
    }
}
