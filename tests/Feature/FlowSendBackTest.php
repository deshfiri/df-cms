<?php

namespace Tests\Feature;

use App\Exceptions\FlowException;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowTransition;
use App\Models\User;
use App\Notifications\FlowItemAwaitingYou;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sending work back to any earlier stage, not just the one before.
 *
 * A problem found at Review is often a Brief problem. Bouncing it back one stage
 * at a time makes every stage in between pass it along untouched, so the sender
 * may now choose how far back it goes.
 *
 * The boundary that matters: this only ever moves *backwards*. Forward is still
 * one stage at a time, so no stage can be skipped by dressing a jump ahead up as
 * a send-back.
 */
class FlowSendBackTest extends TestCase
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

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /**
     * A four-stage workflow — Brief, Design, Review, Approval — with a different
     * person on each, and an item already sitting at Review held by the reviewer.
     *
     * @return array{0:FlowItem, 1:array<int,\App\Models\FlowStage>, 2:array<int,User>}
     */
    private function itemAtReview(): array
    {
        $people = [$this->user(), $this->user(), $this->user(), $this->user()];
        $admin  = tap($this->user())->givePermissionTo('manage workflows');

        $flow   = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $stages = [];
        foreach (['Brief', 'Design', 'Review', 'Approval'] as $i => $name) {
            $stage = $flow->stages()->create(['name' => $name, 'position' => $i + 1]);
            $stage->users()->sync([$people[$i]->id]);
            $stages[] = $stage;
        }

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Logo'], $admin);

        $this->flow->claim($item->fresh(), $people[0]);
        $this->flow->advance($item->fresh(), $people[0]);    // -> Design
        $this->flow->claim($item->fresh(), $people[1]);
        $this->flow->advance($item->fresh(), $people[1]);    // -> Review
        $this->flow->claim($item->fresh(), $people[2]);

        return [$item->fresh(), $stages, $people];
    }

    // ── Choosing how far back ────────────────────────────────────────────

    public function test_work_can_be_sent_back_two_stages(): void
    {
        [$item, [$brief, , $review], $people] = $this->itemAtReview();

        $this->flow->sendBack($item, $people[2], 'The brief itself is wrong', null, $brief->id);

        $item->refresh();
        $this->assertSame($brief->id, $item->current_stage_id);

        // Recorded as one move straight from Review to Brief.
        $last = FlowTransition::where('flow_item_id', $item->id)->latest('id')->first();
        $this->assertSame($review->id, $last->from_stage_id);
        $this->assertSame($brief->id, $last->to_stage_id);
        $this->assertSame('The brief itself is wrong', $last->note);
    }

    /** Leaving the stage out behaves exactly as before: one stage back. */
    public function test_without_a_stage_it_still_goes_back_one(): void
    {
        [$item, [, $design], $people] = $this->itemAtReview();

        $this->flow->sendBack($item, $people[2], 'Colours are off');

        $this->assertSame($design->id, $item->fresh()->current_stage_id);
    }

    /** Rework goes back to whoever last handled the chosen stage, not the one in between. */
    public function test_it_is_returned_to_whoever_handled_that_stage(): void
    {
        [$item, [$brief], $people] = $this->itemAtReview();

        $this->flow->sendBack($item, $people[2], 'Start again', null, $brief->id);

        $this->assertSame($people[0]->id, $item->fresh()->assigned_to);
        Notification::assertSentTo($people[0], FlowItemAwaitingYou::class);
    }

    public function test_it_can_be_addressed_to_someone_on_the_chosen_stage(): void
    {
        [$item, [$brief], $people] = $this->itemAtReview();

        $colleague = $this->user();
        $brief->users()->attach($colleague->id);

        $this->flow->sendBack($item, $people[2], 'Start again', $colleague->id, $brief->id);

        $this->assertSame($colleague->id, $item->fresh()->assigned_to);
    }

    /** The person must belong to the stage it is going to, not the one it came from. */
    public function test_it_cannot_be_addressed_to_someone_not_on_the_chosen_stage(): void
    {
        [$item, [$brief], $people] = $this->itemAtReview();

        $this->expectException(FlowException::class);

        // people[1] is on Design, not Brief.
        $this->flow->sendBack($item, $people[2], 'Start again', $people[1]->id, $brief->id);
    }

    // ── The boundary ─────────────────────────────────────────────────────

    /** "Send back" must never become a way to skip ahead past stages. */
    public function test_it_cannot_be_sent_forward_through_send_back(): void
    {
        [$item, [, , , $approval], $people] = $this->itemAtReview();

        try {
            $this->flow->sendBack($item, $people[2], 'Skipping ahead', null, $approval->id);
            $this->fail('A later stage was accepted as a send-back destination.');
        } catch (FlowException) {
            // expected
        }

        $this->assertNotSame($approval->id, $item->fresh()->current_stage_id);
    }

    public function test_it_cannot_be_sent_back_to_the_stage_it_is_already_at(): void
    {
        [$item, [, , $review], $people] = $this->itemAtReview();

        $this->expectException(FlowException::class);

        $this->flow->sendBack($item, $people[2], 'Same place', null, $review->id);
    }

    /** A stage id from a different workflow is refused, never followed. */
    public function test_a_stage_from_another_workflow_is_refused(): void
    {
        [$item, , $people] = $this->itemAtReview();

        $admin = tap($this->user())->givePermissionTo('manage workflows');
        $other = Flow::create(['name' => 'Other', 'is_active' => true, 'created_by' => $admin->id]);
        $foreign = $other->stages()->create(['name' => 'Elsewhere', 'position' => 1]);

        $this->expectException(FlowException::class);

        $this->flow->sendBack($item, $people[2], 'Wrong workflow', null, $foreign->id);
    }

    public function test_the_endpoint_refuses_a_later_stage(): void
    {
        [$item, [, , , $approval], $people] = $this->itemAtReview();

        $this->actingAs($people[2])
            ->postJson(route('flow-items.send-back', $item), [
                'reason'      => 'Skipping ahead',
                'to_stage_id' => $approval->id,
            ])
            ->assertStatus(422);

        $this->assertNotSame($approval->id, $item->fresh()->current_stage_id);
    }

    public function test_the_endpoint_accepts_an_earlier_stage(): void
    {
        [$item, [$brief], $people] = $this->itemAtReview();

        $this->actingAs($people[2])
            ->postJson(route('flow-items.send-back', $item), [
                'reason'      => 'Back to the brief',
                'to_stage_id' => $brief->id,
            ])
            ->assertOk();

        $this->assertSame($brief->id, $item->fresh()->current_stage_id);
    }

    // ── What the dialog is offered ───────────────────────────────────────

    public function test_the_dialog_is_offered_every_earlier_stage_nearest_first(): void
    {
        [$item, , $people] = $this->itemAtReview();

        $this->actingAs($people[2])
            ->getJson(route('flow-items.handoff', $item))
            ->assertOk()
            ->assertJsonCount(2, 'earlier')
            ->assertJsonPath('earlier.0.name', 'Design')
            ->assertJsonPath('earlier.1.name', 'Brief')
            // Forward stays a single next stage.
            ->assertJsonPath('next.name', 'Approval')
            // The old single-step key is still there for anything reading it.
            ->assertJsonPath('previous.name', 'Design');
    }
}
