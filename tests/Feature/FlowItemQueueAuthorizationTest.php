<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A queue item belongs to whoever it's currently assigned to. Nobody else
 * can edit or cancel it — not even the person who created it — unless they
 * hold "manage workflows". Mirrors FlowService::canManageItem() at the HTTP
 * layer (PUT flow-items/{item}, POST flow-items/{item}/cancel).
 */
class FlowItemQueueAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manage workflows', 'view workflows'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function admin(): User
    {
        return tap($this->user())->givePermissionTo('manage workflows');
    }

    private function flowWithStage(User $creator, array $stageUsers = []): array
    {
        $flow  = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $creator->id]);
        $stage = $flow->stages()->create(['name' => 'Draft', 'position' => 1]);
        $stage->users()->sync(collect($stageUsers)->pluck('id'));

        return [$flow->refresh(), $stage];
    }

    private function item($flow, $stage, User $creator, ?User $assignee = null): FlowItem
    {
        return FlowItem::create([
            'flow_id' => $flow->id, 'current_stage_id' => $stage->id,
            'title' => 'Original title', 'status' => FlowItem::STATUS_OPEN,
            'created_by' => $creator->id, 'assigned_to' => $assignee?->id,
        ]);
    }

    // ── Editing ───────────────────────────────────────────────────────────

    public function test_the_assignee_can_edit_the_item(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->actingAs($assignee)->putJson(route('flow-items.update', $item), [
            'title' => 'Updated title',
        ])->assertOk();

        $this->assertSame('Updated title', $item->fresh()->title);
    }

    public function test_an_admin_can_edit_any_item(): void
    {
        $creator = $this->user();
        $admin   = $this->admin();
        [$flow, $stage] = $this->flowWithStage($creator);
        $item = $this->item($flow, $stage, $creator); // unclaimed

        $this->actingAs($admin)->putJson(route('flow-items.update', $item), [
            'title' => 'Fixed by admin',
        ])->assertOk();

        $this->assertSame('Fixed by admin', $item->fresh()->title);
    }

    public function test_the_creator_cannot_edit_an_item_assigned_to_someone_else(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->actingAs($creator)->putJson(route('flow-items.update', $item), [
            'title' => 'Should not apply',
        ])->assertForbidden();

        $this->assertSame('Original title', $item->fresh()->title);
    }

    public function test_the_creator_cannot_edit_their_own_unclaimed_item(): void
    {
        $creator = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator);
        $item = $this->item($flow, $stage, $creator); // unclaimed

        $this->actingAs($creator)->putJson(route('flow-items.update', $item), [
            'title' => 'Should not apply',
        ])->assertForbidden();

        $this->assertSame('Original title', $item->fresh()->title);
    }

    public function test_an_unrelated_user_cannot_edit_the_item(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        $stranger = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->actingAs($stranger)->putJson(route('flow-items.update', $item), [
            'title' => 'Should not apply',
        ])->assertForbidden();
    }

    // ── Cancelling ────────────────────────────────────────────────────────

    public function test_the_assignee_can_cancel_the_item(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->actingAs($assignee)->postJson(route('flow-items.cancel', $item), [])->assertOk();

        $this->assertSame(FlowItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_the_creator_cannot_cancel_an_item_assigned_to_someone_else(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->actingAs($creator)->postJson(route('flow-items.cancel', $item), [])->assertStatus(422);

        $this->assertSame(FlowItem::STATUS_OPEN, $item->fresh()->status);
    }

    public function test_an_admin_can_cancel_any_item(): void
    {
        $creator = $this->user();
        $admin   = $this->admin();
        [$flow, $stage] = $this->flowWithStage($creator);
        $item = $this->item($flow, $stage, $creator); // unclaimed

        $this->actingAs($admin)->postJson(route('flow-items.cancel', $item), [])->assertOk();

        $this->assertSame(FlowItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    // ── The item page itself doesn't offer controls it would then refuse ──

    public function test_a_non_privileged_viewer_is_not_offered_edit_or_cancel_controls(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        // The creator can still open (view) the item — just not act on it.
        $this->actingAs($creator)->get(route('flow-items.show', $item))
            ->assertOk()
            ->assertDontSee('id="editItemBtn"', false)
            ->assertDontSee('id="cancelItemBtn"', false);
    }

    public function test_the_assignee_is_offered_the_edit_controls(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->actingAs($assignee)->get(route('flow-items.show', $item))
            ->assertOk()
            ->assertSee('id="editItemBtn"', false)
            ->assertSee('id="cancelItemBtn"', false);
    }
}
