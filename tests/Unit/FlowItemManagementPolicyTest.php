<?php

namespace Tests\Unit;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FlowService::canManageItem() / canAct() in isolation: a queue item belongs
 * to whoever it's currently assigned to, not whoever created it. Only a
 * "manage workflows" admin is exempt from needing the assignment.
 */
class FlowItemManagementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flow = app(FlowService::class);
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

    private function flowWithStage(User $creator, array $stageUsers = []): array
    {
        $flow  = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $creator->id]);
        $stage = $flow->stages()->create(['name' => 'Draft', 'position' => 1]);
        $stage->users()->sync(collect($stageUsers)->pluck('id'));

        return [$flow->refresh(), $stage];
    }

    private function item(Flow $flow, $stage, User $creator, ?User $assignee = null): FlowItem
    {
        return FlowItem::create([
            'flow_id' => $flow->id, 'current_stage_id' => $stage->id,
            'title' => 'x', 'status' => FlowItem::STATUS_OPEN,
            'created_by' => $creator->id, 'assigned_to' => $assignee?->id,
        ]);
    }

    // ── canManageItem: edit / cancel ─────────────────────────────────────

    public function test_the_current_assignee_may_manage_the_item(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->assertTrue($this->flow->canManageItem($assignee, $item));
    }

    public function test_an_admin_may_manage_any_item_regardless_of_assignment(): void
    {
        $creator = $this->user();
        $admin   = $this->admin();
        [$flow, $stage] = $this->flowWithStage($creator);
        $item = $this->item($flow, $stage, $creator); // unclaimed

        $this->assertTrue($this->flow->canManageItem($admin, $item));
    }

    public function test_the_creator_alone_may_not_manage_an_item_assigned_to_someone_else(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->assertFalse($this->flow->canManageItem($creator, $item));
    }

    public function test_the_creator_alone_may_not_manage_their_own_unclaimed_item(): void
    {
        $creator = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator);
        $item = $this->item($flow, $stage, $creator); // unclaimed, creator is not the assignee

        $this->assertFalse($this->flow->canManageItem($creator, $item));
    }

    public function test_an_unrelated_user_may_not_manage_the_item(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        $stranger = $this->user();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        $this->assertFalse($this->flow->canManageItem($stranger, $item));
    }

    // ── canAct: advance / send back — the rule canManageItem now matches ──

    public function test_canAct_and_canManageItem_agree_on_who_may_touch_the_item(): void
    {
        $creator  = $this->user();
        $assignee = $this->user();
        $stranger = $this->user();
        $admin    = $this->admin();
        [$flow, $stage] = $this->flowWithStage($creator, [$assignee, $stranger, $admin]);
        $item = $this->item($flow, $stage, $creator, $assignee);

        foreach ([$assignee, $admin] as $allowed) {
            $this->assertSame($this->flow->canAct($allowed, $item), $this->flow->canManageItem($allowed, $item));
            $this->assertTrue($this->flow->canManageItem($allowed, $item));
        }
        foreach ([$creator, $stranger] as $blocked) {
            $this->assertSame($this->flow->canAct($blocked, $item), $this->flow->canManageItem($blocked, $item));
            $this->assertFalse($this->flow->canManageItem($blocked, $item));
        }
    }
}
