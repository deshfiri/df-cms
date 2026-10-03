<?php

namespace Tests\Feature;

use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The performance engine's other side of true shared ownership: a task with
 * several current assignees is not scored as if it belonged to just one of
 * them — each gets their own share of task credit, independently, not a
 * pool split evenly by headcount. Also confirms Output Volume's
 * task_workload scope (PerformanceCalculationService::outputVolume()) keeps
 * that same "each current assignee independently gets their own full
 * share" rule rather than pooling or doubling credit for a shared task.
 */
class PerformanceMultiAssigneeTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        $this->manager = User::factory()->create(['is_active' => true]);
    }

    private function sharedTask(array $assignees, string $status = 'Completed'): Task
    {
        $task = Task::create([
            'title' => 'Shared work', 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'created_by' => $this->manager->id,
            'due_date' => '2026-09-10', 'completion_date' => $status === 'Completed' ? '2026-09-10' : null,
        ]);
        $task->assignees()->sync(array_map(fn (User $u) => $u->id, $assignees));

        return $task;
    }

    private function score(): PerformanceCalculationService
    {
        return app(PerformanceCalculationService::class);
    }

    public function test_two_assignees_on_the_same_completed_task_both_score_completion_credit(): void
    {
        $anika  = User::factory()->create(['is_active' => true]);
        $bashir = User::factory()->create(['is_active' => true]);
        $this->sharedTask([$anika, $bashir]);

        $anikas  = $this->score()->taskCompletion($anika, self::PERIOD);
        $bashirs = $this->score()->taskCompletion($bashir, self::PERIOD);

        $this->assertSame(1, $anikas['total']);
        $this->assertSame(1, $bashirs['total']);
        $this->assertSame(100.0, $anikas['completion_pct']);
        $this->assertSame(100.0, $bashirs['completion_pct']);
    }

    public function test_finalScore_reflects_each_assignees_own_share_not_a_shared_pool(): void
    {
        KpiWeightConfig::create([
            'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 100, 'on_time_weight' => 0, 'revision_weight' => 0,
            'sales_weight' => 0, 'satisfaction_weight' => 0, 'client_care_weight' => 0,
            'daily_target_weight' => 0, 'output_volume_weight' => 0,
        ]);
        $anika  = User::factory()->create(['is_active' => true]);
        $bashir = User::factory()->create(['is_active' => true]);
        $this->sharedTask([$anika, $bashir]);

        $anikaScore  = $this->score()->finalScore($anika, self::PERIOD);
        $bashirScore = $this->score()->finalScore($bashir, self::PERIOD);

        // Neither did any logged work (task created directly, no activity), so
        // both are current holders with no recorded involvement — full credit
        // each, independently, same as a solo task.
        $this->assertSame(100.0, $anikaScore['final_score']);
        $this->assertSame(100.0, $bashirScore['final_score']);
    }

    /**
     * A shared task's workload credit for Output Volume's task_workload
     * scope follows the exact same per-assignee share Task Completion
     * already uses (see TaskWorkloadPerformanceTest for the scope's own
     * dedicated coverage) — each current assignee independently earns their
     * own full share of the one shared task, not half each and not a
     * doubled, pooled credit for the pair.
     */
    public function test_a_shared_completed_task_credits_each_assignees_own_share_of_task_workload(): void
    {
        $anika  = User::factory()->create(['is_active' => true]);
        $bashir = User::factory()->create(['is_active' => true]);
        $this->sharedTask([$anika, $bashir]);

        $this->assertSame(100.0, $this->score()->taskCompletion($anika, self::PERIOD)['completion_pct']);
        $this->assertSame(100.0, $this->score()->taskCompletion($bashir, self::PERIOD)['completion_pct']);

        $anikaVolume  = $this->score()->outputVolume($anika, self::PERIOD);
        $bashirVolume = $this->score()->outputVolume($bashir, self::PERIOD);

        // Neither is pooled or doubled — each independently credits exactly
        // the one shared task's full workload credit, same as Task
        // Completion does, and (with only the two of them) each is the
        // other's cohort max, so both land on 100%.
        $this->assertEqualsWithDelta(1.0, $anikaVolume['scopes']['task_workload']['mine'], 0.01);
        $this->assertEqualsWithDelta(1.0, $bashirVolume['scopes']['task_workload']['mine'], 0.01);
        $this->assertSame(100.0, $anikaVolume['scopes']['task_workload']['pct']);
        $this->assertSame(100.0, $bashirVolume['scopes']['task_workload']['pct']);
    }
}
