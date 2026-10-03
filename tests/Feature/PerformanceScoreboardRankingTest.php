<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The scoreboard sorted purely by the rounded final_score with no tie-break.
 * Two employees commonly tie at the same score (e.g. both at 100% on every
 * KPI that applies to them) while having done very different amounts of
 * actual work — the tie used to fall back to the pre-sort alphabetical name
 * order instead of to who did more.
 */
class PerformanceScoreboardRankingTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;
    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        foreach (['view performance', 'manage performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view performance', 'manage performance']);
        $this->flow = Flow::create(['name' => 'Test Flow', 'is_active' => true]);

        // Score purely on output volume so both employees can legitimately
        // tie on final_score despite very different task workloads.
        KpiWeightConfig::create([
            'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 0, 'on_time_weight' => 0, 'revision_weight' => 0,
            'sales_weight' => 0, 'satisfaction_weight' => 0, 'client_care_weight' => 0,
            'daily_target_weight' => 0, 'output_volume_weight' => 100,
        ]);
    }

    private function flowItem(User $assignee): void
    {
        FlowItem::create([
            'flow_id' => $this->flow->id, 'title' => 'Item', 'status' => FlowItem::STATUS_COMPLETED,
            'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
            'due_date' => '2026-09-10', 'completed_at' => '2026-09-10',
        ]);
    }

    public function test_a_tied_final_score_breaks_toward_the_employee_with_more_credited_work(): void
    {
        // Named so plain alphabetical order would rank Aaron ahead of Zoe —
        // the opposite of what should happen once credited volume breaks
        // the tie.
        $aaron = User::factory()->create(['name' => 'Aaron Lowvolume', 'is_active' => true]);
        $zoe   = User::factory()->create(['name' => 'Zoe Highvolume', 'is_active' => true]);

        $this->flowItem($aaron);
        $this->flowItem($zoe);

        // Zoe did real task work this period; Aaron did none. Both can
        // still tie on final_score because only output_volume is weighted.
        $task = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'created_by' => $this->manager->id,
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $task->assignees()->sync([$zoe->id]);

        $response = $this->actingAs($this->manager)
            ->get(route('performance.index', ['period' => self::PERIOD]))
            ->assertOk();

        $rows = collect($response->viewData('rows'));

        $aaronRow = $rows->firstWhere('id', $aaron->id);
        $zoeRow   = $rows->firstWhere('id', $zoe->id);

        $this->assertNotNull($aaronRow['final_score']);
        $this->assertSame($aaronRow['final_score'], $zoeRow['final_score']);
        $this->assertGreaterThan($aaronRow['credited_total'], $zoeRow['credited_total']);

        // Tied on score — Zoe's extra credited work must still rank her
        // first, not Aaron's alphabetical head start.
        $this->assertLessThan($aaronRow['rank'], $zoeRow['rank']);
    }
}
