<?php

namespace Tests\Unit;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use App\Services\WorkflowPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feeds the admin/manager dashboard's "Workflow Pipeline" and "Workflow
 * Stage Completion" widgets. Before this existed, both read the old,
 * retired WorkflowStage/ClientStageProgress pipeline — dummy data, since
 * nothing driving real work writes to it any more.
 */
class WorkflowPipelineServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowPipelineService $pipeline;
    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = app(WorkflowPipelineService::class);
        $this->flow = app(FlowService::class);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_with_no_lead_flow_everything_is_empty(): void
    {
        Flow::create(['name' => 'Not lead', 'is_active' => true]);

        $this->assertNull($this->pipeline->leadFlow());
        $this->assertSame([], $this->pipeline->segments());
        $this->assertSame(['labels' => [], 'data' => []], $this->pipeline->completionChart());
    }

    public function test_segments_reflect_items_in_progress_and_cleared(): void
    {
        $creator = $this->user();
        $worker = $this->user();

        $flow = Flow::create(['name' => 'Lead Flow', 'is_active' => true, 'is_lead' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1])->users()->sync([$worker->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$worker->id]);
        $flow->refresh();

        // Item 1: still sitting at Draft.
        $this->flow->createItem($flow, ['title' => 'Item 1'], $creator);

        // Item 2: claimed and advanced past Draft, into Review.
        $item2 = $this->flow->createItem($flow, ['title' => 'Item 2'], $creator);
        $item2 = $this->flow->claim($item2->fresh(), $worker);
        $this->flow->advance($item2, $worker);

        $segments = $this->pipeline->segments();
        $this->assertCount(2, $segments);

        $this->assertSame('Draft', $segments[0]['label']);
        $this->assertSame(1, $segments[0]['active']); // item 1 still there
        $this->assertSame(50, $segments[0]['progress']); // 1 of 2 items cleared Draft

        $this->assertSame('Review', $segments[1]['label']);
        $this->assertSame(1, $segments[1]['active']); // item 2 now here
        $this->assertSame(0, $segments[1]['progress']); // nobody has cleared Review yet
    }

    public function test_a_completed_item_counts_as_cleared_for_every_stage(): void
    {
        $creator = $this->user();
        $worker = $this->user();

        $flow = Flow::create(['name' => 'Lead Flow', 'is_active' => true, 'is_lead' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);
        $flow->refresh();

        $item = $this->flow->createItem($flow, ['title' => 'Item'], $creator);
        $item = $this->flow->claim($item->fresh(), $worker);
        $this->flow->advance($item, $worker); // completes the only stage

        $segments = $this->pipeline->segments();
        $this->assertSame(100, $segments[0]['progress']);
        $this->assertSame(0, $segments[0]['active']); // no longer open at any stage

        $chart = $this->pipeline->completionChart();
        $this->assertSame(['Only Stage'], $chart['labels']);
        $this->assertSame([1], $chart['data']);
    }

    public function test_cancelled_items_are_excluded_entirely(): void
    {
        $creator = $this->user();
        $flow = Flow::create(['name' => 'Lead Flow', 'is_active' => true, 'is_lead' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1]);
        $flow->refresh();

        $item = $this->flow->createItem($flow, ['title' => 'Item'], $creator);
        $item->update(['status' => FlowItem::STATUS_CANCELLED]);

        $segments = $this->pipeline->segments();
        $this->assertSame(0, $segments[0]['active']);
        $this->assertSame(0, $segments[0]['progress']);
    }

    public function test_items_at_a_stage_for_over_a_week_count_as_delayed(): void
    {
        $creator = $this->user();
        $flow = Flow::create(['name' => 'Lead Flow', 'is_active' => true, 'is_lead' => true, 'created_by' => $creator->id]);
        $flow->stages()->create(['name' => 'Draft', 'position' => 1]);
        $flow->refresh();

        $item = $this->flow->createItem($flow, ['title' => 'Item'], $creator);
        // A plain query-builder update, since the Eloquent instance method would
        // re-touch updated_at back to now() regardless of what we set it to.
        FlowItem::where('id', $item->id)->update(['updated_at' => now()->subDays(10)]);

        $segments = $this->pipeline->segments();
        $this->assertSame(1, $segments[0]['delayed']);
    }

    public function test_only_one_flow_may_be_lead_at_once(): void
    {
        $creator = $this->user();
        $a = Flow::create(['name' => 'A', 'is_active' => true, 'is_lead' => true, 'created_by' => $creator->id]);
        Flow::create(['name' => 'B', 'is_active' => true, 'created_by' => $creator->id]);

        $this->assertSame($a->id, $this->pipeline->leadFlow()->id);
    }
}
