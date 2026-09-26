<?php

namespace Tests\Feature;

use App\Models\DailyTarget;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * FlowService::advance() clears assigned_to the instant an item completes —
 * it's "who currently holds it," not a record of who did the work. Every
 * KPI that counted "workflow items completed" filtered on assigned_to, so a
 * genuinely-completed item — reached through the real claim → advance flow,
 * not a synthetic fixture — was invisible to its own completer from the
 * moment it finished: Output Volume's Workflow Items row, and Daily Target's
 * workflow scope, both silently stayed at zero for everyone. These tests go
 * through the real FlowService, unlike the synthetic FlowItem::create()
 * fixtures elsewhere in this suite, which don't reproduce that condition.
 */
class FlowItemCompletionCreditTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;
    private PerformanceCalculationService $performance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->flow = app(FlowService::class);
        $this->performance = app(PerformanceCalculationService::class);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /** Builds and completes a one-stage flow item end to end, the way real usage does it. */
    private function completeAnItemFor(User $worker, string $dueDate): FlowItem
    {
        $admin = $this->user();
        $flow  = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => $dueDate], $admin);
        $item = $this->flow->claim($item->fresh(), $worker);

        return $this->flow->advance($item, $worker);
    }

    public function test_a_genuinely_completed_item_is_credited_to_whoever_finished_it(): void
    {
        $worker = $this->user();
        $item   = $this->completeAnItemFor($worker, '2026-09-10');

        $this->assertSame(FlowItem::STATUS_COMPLETED, $item->status);
        $this->assertNull($item->assigned_to, 'sanity check: completion really does clear this');
        $this->assertSame($worker->id, $item->completed_by);

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    public function test_the_cohort_leader_is_found_even_though_completion_clears_assigned_to(): void
    {
        $leader = $this->user();
        $trailing = $this->user();
        $this->completeAnItemFor($leader, '2026-09-10');
        $this->completeAnItemFor($leader, '2026-09-11');
        $this->completeAnItemFor($trailing, '2026-09-12');

        $scope = $this->performance->outputVolume($trailing, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
        $this->assertSame(2.0, $scope['cohort_max']);
        $this->assertSame(50.0, $scope['pct']);
    }

    public function test_daily_targets_workflow_scope_also_counts_a_real_completion(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-20 12:00:00'));
        $worker = $this->user();
        DailyTarget::create(['user_id' => $worker->id, 'scope' => 'workflow', 'target_quantity' => 1]);
        $this->completeAnItemFor($worker, '2026-09-05');

        $scope = $this->performance->dailyTargetAchievement($worker, '2026-09')['scopes']['workflow'];

        $this->assertSame(1, $scope['available']);
        $this->assertSame(1, $scope['completed']);
    }

    public function test_prefetching_a_cohort_still_credits_a_completed_item(): void
    {
        $worker = $this->user();
        $item   = $this->completeAnItemFor($worker, '2026-09-10');

        $this->performance->prefetch([$worker], '2026-09');
        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];

        $this->assertSame(1.0, $scope['mine']);
    }
}
