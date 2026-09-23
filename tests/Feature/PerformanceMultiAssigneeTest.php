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
 * pool split evenly by headcount. Also guards against a task earning credit
 * twice for the same work: Output Volume has no task scope precisely
 * because Task Completion already scores every task an assignee is given.
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
     * Output Volume has no task scope (Task Completion already scores every
     * task an assignee is given — see PerformanceCalculationService::
     * outputVolume()), so a shared task earning both assignees a perfect
     * Task Completion rate must not also hand them a matching Output
     * Volume score off the same tasks — that was the exact double-count a
     * multi-assignee task made easy to fall into.
     */
    public function test_a_shared_completed_task_does_not_also_score_output_volume(): void
    {
        $anika  = User::factory()->create(['is_active' => true]);
        $bashir = User::factory()->create(['is_active' => true]);
        $this->sharedTask([$anika, $bashir]);

        $this->assertSame(100.0, $this->score()->taskCompletion($anika, self::PERIOD)['completion_pct']);
        $this->assertSame(100.0, $this->score()->taskCompletion($bashir, self::PERIOD)['completion_pct']);

        $this->assertNull($this->score()->outputVolume($anika, self::PERIOD));
        $this->assertNull($this->score()->outputVolume($bashir, self::PERIOD));
    }
}
