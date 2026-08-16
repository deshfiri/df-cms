<?php

namespace Tests\Feature;

use App\Exceptions\FlowException;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowItemComment;
use App\Models\FlowTransition;
use App\Models\User;
use App\Notifications\FlowItemAwaitingYou;
use App\Notifications\FlowItemCompleted;
use App\Notifications\FlowItemNewComment;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FlowEngineTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flow = app(FlowService::class);
        // can('manage workflows') / givePermissionTo need the permission to exist.
        Permission::firstOrCreate(['name' => 'manage workflows', 'guard_name' => 'web']);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function admin(): User
    {
        return tap($this->user())->givePermissionTo('manage workflows');
    }

    /** @param array<int,array{0:string,1:array<int,User>}> $stages */
    private function buildFlow(User $creator, array $stages): array
    {
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $creator->id]);
        $built = [];
        foreach ($stages as $i => [$name, $users]) {
            $stage = $flow->stages()->create(['name' => $name, 'position' => $i + 1]);
            $stage->users()->sync(collect($users)->pluck('id'));
            $built[] = $stage;
        }

        return [$flow->refresh(), $built];
    }

    public function test_a_new_item_enters_the_first_stage_and_notifies_its_users(): void
    {
        Notification::fake();
        $a = $this->user();
        $creator = $this->admin();
        [$flow, [$s1]] = $this->buildFlow($creator, [['Draft', [$a]], ['Review', [$this->user()]]]);

        $item = $this->flow->createItem($flow, ['title' => 'Logo'], $creator);

        $this->assertSame($s1->id, $item->current_stage_id);
        $this->assertSame(FlowItem::STATUS_OPEN, $item->status);
        $this->assertSame(1, $item->transitions()->count()); // creation transition
        Notification::assertSentTo($a, FlowItemAwaitingYou::class);
    }

    public function test_work_must_be_claimed_before_it_can_be_advanced(): void
    {
        $a = $this->user();
        $b = $this->user();
        [$flow, [, $s2]] = $this->buildFlow($this->admin(), [['Draft', [$a]], ['Review', [$b]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());

        // Unclaimed: even the assigned user can't advance until they claim it.
        try {
            $this->flow->advance($item->fresh(), $a);
            $this->fail('Expected FlowException (claim first)');
        } catch (FlowException $e) {
            $this->assertStringContainsString('Claim this item', $e->getMessage());
        }

        // b is not on stage 1 → can't claim it.
        try {
            $this->flow->claim($item->fresh(), $b);
            $this->fail('Expected FlowException (not a stage assignee)');
        } catch (FlowException $e) {
            $this->assertStringContainsString('assigned to this stage', $e->getMessage());
        }

        // a claims, then advances → moves on, ownership reset for the next team.
        $this->flow->claim($item->fresh(), $a);
        $this->flow->advance($item->fresh(), $a);
        $this->assertSame($s2->id, $item->fresh()->current_stage_id);
        $this->assertNull($item->fresh()->assigned_to);
    }

    public function test_advancing_the_last_stage_completes_and_notifies_the_creator(): void
    {
        Notification::fake();
        $a = $this->user();
        $creator = $this->admin();
        [$flow] = $this->buildFlow($creator, [['Only', [$a]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $creator);

        $this->flow->claim($item->fresh(), $a);
        $this->flow->advance($item->fresh(), $a);

        $item->refresh();
        $this->assertSame(FlowItem::STATUS_COMPLETED, $item->status);
        $this->assertNull($item->current_stage_id);
        $this->assertNotNull($item->completed_at);
        Notification::assertSentTo($creator, FlowItemCompleted::class);
    }

    public function test_send_back_moves_to_the_previous_stage_with_a_reason(): void
    {
        $a = $this->user();
        $b = $this->user();
        [$flow, [$s1, $s2]] = $this->buildFlow($this->admin(), [['Draft', [$a]], ['Review', [$b]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());
        $this->flow->claim($item->fresh(), $a);
        $this->flow->advance($item->fresh(), $a); // -> Review (unclaimed)

        $this->flow->claim($item->fresh(), $b);
        $this->flow->sendBack($item->fresh(), $b, 'Wrong colours');

        $item->refresh();
        $this->assertSame($s1->id, $item->current_stage_id);
        $last = FlowTransition::where('flow_item_id', $item->id)->orderByDesc('id')->first();
        $this->assertSame($s2->id, $last->from_stage_id);
        $this->assertSame($s1->id, $last->to_stage_id);
        $this->assertSame('Wrong colours', $last->note);
    }

    public function test_cancel_withdraws_the_item_and_removes_it_from_the_queue(): void
    {
        $a = $this->user();
        $creator = $this->admin();
        [$flow] = $this->buildFlow($creator, [['Draft', [$a]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $creator);

        $this->assertCount(1, $this->flow->myQueue($a));

        $this->flow->cancelItem($item->fresh(), $creator, 'Not needed');

        $item->refresh();
        $this->assertSame(FlowItem::STATUS_CANCELLED, $item->status);
        $this->assertNull($item->current_stage_id);
        $this->assertCount(0, $this->flow->myQueue($a));
    }

    public function test_a_non_owner_cannot_cancel(): void
    {
        $a = $this->user();
        [$flow] = $this->buildFlow($this->admin(), [['Draft', [$a]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());

        $this->expectException(FlowException::class);
        $this->flow->cancelItem($item->fresh(), $a, null); // a is neither creator nor admin
    }

    public function test_the_queue_is_ordered_by_urgency(): void
    {
        $a = $this->user();
        $creator = $this->admin();
        [$flow] = $this->buildFlow($creator, [['Do', [$a]]]);

        $this->flow->createItem($flow, ['title' => 'LOW', 'priority' => 'Low'], $creator);
        $this->flow->createItem($flow, ['title' => 'URGENT-OVERDUE', 'priority' => 'Urgent', 'due_date' => now()->subDay()->toDateString()], $creator);
        $this->flow->createItem($flow, ['title' => 'NORMAL', 'priority' => 'Normal'], $creator);
        $this->flow->createItem($flow, ['title' => 'HIGH', 'priority' => 'High', 'due_date' => now()->addDay()->toDateString()], $creator);

        $order = $this->flow->myQueue($a)->pluck('title')->all();
        $this->assertSame(['URGENT-OVERDUE', 'HIGH', 'NORMAL', 'LOW'], $order);
    }

    public function test_admin_can_advance_an_item_they_are_not_assigned_to(): void
    {
        $a = $this->user();
        $admin = $this->admin();
        [$flow, [$s1, $s2]] = $this->buildFlow($admin, [['Draft', [$a]], ['Review', [$this->user()]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $admin);

        // admin is not assigned to Draft, but the override lets them advance.
        $this->flow->advance($item->fresh(), $admin);
        $this->assertSame($s2->id, $item->fresh()->current_stage_id);
    }

    public function test_a_new_comment_notifies_participants_but_not_the_author(): void
    {
        Notification::fake();
        $a = $this->user();       // current-stage assignee
        $creator = $this->admin();
        [$flow] = $this->buildFlow($creator, [['Draft', [$a]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $creator);

        // a comments → creator is notified, a (the author) is not.
        FlowItemComment::create(['flow_item_id' => $item->id, 'user_id' => $a->id, 'body' => 'Question?']);
        $this->flow->notifyNewComment($item->fresh(), $a, 'Question?');

        Notification::assertSentTo($creator, FlowItemNewComment::class);
        Notification::assertNotSentTo($a, FlowItemNewComment::class);
    }

    public function test_claiming_takes_ownership_and_hides_the_item_from_teammates(): void
    {
        $a = $this->user();
        $b = $this->user();
        $creator = $this->admin();
        [$flow] = $this->buildFlow($creator, [['Draft', [$a, $b]]]); // both on the stage
        $item = $this->flow->createItem($flow, ['title' => 'x'], $creator);

        // Unclaimed → both teammates see it in their queue.
        $this->assertCount(1, $this->flow->myQueue($a));
        $this->assertCount(1, $this->flow->myQueue($b));

        $this->flow->claim($item->fresh(), $a);

        $this->assertSame($a->id, $item->fresh()->assigned_to);
        $this->assertCount(1, $this->flow->myQueue($a)); // still a's
        $this->assertCount(0, $this->flow->myQueue($b)); // gone from b's queue

        // b can't claim it (already taken) or advance it (not theirs).
        try {
            $this->flow->claim($item->fresh(), $b);
            $this->fail('Expected FlowException');
        } catch (FlowException $e) {
            $this->assertStringContainsString('already been claimed', $e->getMessage());
        }
        try {
            $this->flow->advance($item->fresh(), $b);
            $this->fail('Expected FlowException');
        } catch (FlowException $e) {
            $this->assertStringContainsString('someone else', $e->getMessage());
        }

        // Releasing returns it to the pool → b sees it again.
        $this->flow->release($item->fresh(), $a);
        $this->assertNull($item->fresh()->assigned_to);
        $this->assertCount(1, $this->flow->myQueue($b));
    }

    public function test_a_hand_off_can_be_addressed_to_one_person_on_the_next_stage(): void
    {
        Notification::fake();
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        [$flow, [, $s2]] = $this->buildFlow($this->admin(), [['Draft', [$a]], ['Review', [$b, $c]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());

        $this->flow->claim($item->fresh(), $a);
        $this->flow->advance($item->fresh(), $a, null, $c->id);

        $item->refresh();
        $this->assertSame($s2->id, $item->current_stage_id);
        $this->assertSame($c->id, $item->assigned_to);   // arrives pre-claimed for c

        // Only c has it; b never sees it despite sharing the stage.
        $this->assertCount(1, $this->flow->myQueue($c));
        $this->assertCount(0, $this->flow->myQueue($b));
        Notification::assertSentTo($c, FlowItemAwaitingYou::class);
        Notification::assertNotSentTo($b, FlowItemAwaitingYou::class);
    }

    public function test_a_hand_off_cannot_be_addressed_to_someone_off_the_stage(): void
    {
        $a = $this->user();
        $outsider = $this->user();
        [$flow, [$s1]] = $this->buildFlow($this->admin(), [['Draft', [$a]], ['Review', [$this->user()]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());
        $this->flow->claim($item->fresh(), $a);

        try {
            $this->flow->advance($item->fresh(), $a, null, $outsider->id);
            $this->fail('Expected FlowException (target not on the stage)');
        } catch (FlowException $e) {
            $this->assertStringContainsString('not an active member', $e->getMessage());
        }

        // Rejected before any write — the item never left its stage.
        $this->assertSame($s1->id, $item->fresh()->current_stage_id);
    }

    public function test_an_inactive_person_cannot_be_addressed(): void
    {
        $a = $this->user();
        $gone = $this->user();
        $gone->update(['is_active' => false]);
        [$flow] = $this->buildFlow($this->admin(), [['Draft', [$a]], ['Review', [$gone]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());
        $this->flow->claim($item->fresh(), $a);

        $this->expectException(FlowException::class);
        $this->flow->advance($item->fresh(), $a, null, $gone->id);
    }

    public function test_send_back_returns_to_whoever_last_handled_that_stage(): void
    {
        $a = $this->user();
        $b = $this->user();   // shares Draft with a but never touched this item
        $c = $this->user();
        [$flow, [$s1]] = $this->buildFlow($this->admin(), [['Draft', [$a, $b]], ['Review', [$c]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());

        $this->flow->claim($item->fresh(), $a);
        $this->flow->advance($item->fresh(), $a);      // a did the Draft work
        $this->flow->claim($item->fresh(), $c);
        $this->flow->sendBack($item->fresh(), $c, 'Wrong colours');

        $item->refresh();
        $this->assertSame($s1->id, $item->current_stage_id);
        $this->assertSame($a->id, $item->assigned_to);  // back to its author, not the pool
        $this->assertCount(0, $this->flow->myQueue($b));
    }

    public function test_send_back_can_be_addressed_to_a_different_person(): void
    {
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        [$flow] = $this->buildFlow($this->admin(), [['Draft', [$a, $b]], ['Review', [$c]]]);
        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());

        $this->flow->claim($item->fresh(), $a);
        $this->flow->advance($item->fresh(), $a);
        $this->flow->claim($item->fresh(), $c);
        $this->flow->sendBack($item->fresh(), $c, 'b should redo this', $b->id);

        $this->assertSame($b->id, $item->fresh()->assigned_to);   // overrides the last handler
    }

    public function test_a_new_item_can_be_addressed_to_a_specific_person(): void
    {
        Notification::fake();
        $a = $this->user();
        $b = $this->user();
        $creator = $this->admin();
        [$flow] = $this->buildFlow($creator, [['Draft', [$a, $b]]]);

        $item = $this->flow->createItem($flow, ['title' => 'x', 'assign_to' => $b->id], $creator);

        $this->assertSame($b->id, $item->assigned_to);
        $this->assertCount(1, $this->flow->myQueue($b));
        $this->assertCount(0, $this->flow->myQueue($a));
        Notification::assertSentTo($b, FlowItemAwaitingYou::class);
        Notification::assertNotSentTo($a, FlowItemAwaitingYou::class);
    }

    public function test_handoff_options_list_the_next_stage_members_with_their_load(): void
    {
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        [$flow] = $this->buildFlow($this->admin(), [['Draft', [$a]], ['Review', [$b, $c]]]);

        // Give b something else to hold so the load figure is non-zero.
        $other = $this->flow->createItem($flow, ['title' => 'other'], $this->admin());
        $this->flow->claim($other->fresh(), $a);
        $this->flow->advance($other->fresh(), $a, null, $b->id);

        $item = $this->flow->createItem($flow, ['title' => 'x'], $this->admin());
        $this->flow->claim($item->fresh(), $a);

        $options = $this->flow->handoffOptions($item->fresh());

        $this->assertFalse($options['is_final']);
        $this->assertSame('Review', $options['next']['name']);
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($options['next']['users'], 'id'));

        $loads = array_column($options['next']['users'], 'load', 'id');
        $this->assertSame(1, $loads[$b->id]);
        $this->assertSame(0, $loads[$c->id]);
    }
}
