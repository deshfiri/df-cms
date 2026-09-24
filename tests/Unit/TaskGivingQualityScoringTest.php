<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\TaskRevision;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Task Giving Quality: the mirror of Quality/revisionRate(), scored against
 * whoever gave a task out rather than whoever did it. Of the tasks someone
 * created this period, what share came back clean rather than needing a
 * "Task Giver Mistake" revision.
 *
 * The load-bearing rule this whole feature exists for: a "Task Giver
 * Mistake" revision must never count against the assignee's own quality
 * KPI — only "Employee Mistake" does. See
 * PerformanceCalculationService::taskGivingQuality() and revisionRate().
 */
class TaskGivingQualityScoringTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    }

    /** A task given by $creator, with its brief written at $createdAt, optionally with assignee + revisions. */
    private function taskGivenBy(User $creator, ?User $assignee, string $createdAt, array $revisionReasons = []): Task
    {
        $this->travelTo(Carbon::parse($createdAt));

        $task = Task::create([
            'title' => 'Brief', 'priority' => 'Medium', 'status' => 'Submitted', 'type' => 'Other',
            'created_by' => $creator->id, 'due_date' => '2026-09-25',
        ]);
        if ($assignee) {
            $task->assignees()->sync([$assignee->id]);
        }

        foreach ($revisionReasons as $reason) {
            TaskRevision::create([
                'task_id' => $task->id, 'requested_by' => $assignee?->id ?? $creator->id,
                'reason_category' => $reason, 'previous_status' => 'Submitted',
            ]);
        }

        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));

        return $task;
    }

    private function quality(User $user): ?array
    {
        return app(PerformanceCalculationService::class)->taskGivingQuality($user, self::PERIOD);
    }

    public function test_nobody_who_gave_out_nothing_is_scored_on_it(): void
    {
        $idle = User::factory()->create(['is_active' => true]);

        $this->assertNull($this->quality($idle));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($idle, self::PERIOD)['scores']['task_giving']);
    }

    public function test_a_clean_brief_scores_100(): void
    {
        $giver = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, User::factory()->create(['is_active' => true]), '2026-09-05');

        $result = $this->quality($giver);

        $this->assertSame(1, $result['total_given']);
        $this->assertSame(1, $result['clean_first_time']);
        $this->assertSame(0, $result['flawed']);
        $this->assertSame(100.0, $result['pct']);
    }

    public function test_a_task_giver_mistake_revision_counts_against_the_giver(): void
    {
        $giver    = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, $assignee, '2026-09-05', ['Task Giver Mistake']);

        $result = $this->quality($giver);

        $this->assertSame(1, $result['total_given']);
        $this->assertSame(0, $result['clean_first_time']);
        $this->assertSame(1, $result['flawed']);
        $this->assertSame(0.0, $result['pct']);
    }

    /**
     * The whole point of this feature: the assignee is never penalised for
     * a mistake that was the task giver's — a "Task Giver Mistake" revision
     * must not touch the assignee's own revisionRate() quality KPI.
     */
    public function test_a_task_giver_mistake_revision_does_not_affect_the_assignees_own_quality_kpi(): void
    {
        $giver    = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, $assignee, '2026-09-05', ['Task Giver Mistake']);

        $assigneeRevision = app(PerformanceCalculationService::class)->revisionRate($assignee, self::PERIOD);

        $this->assertSame(0, $assigneeRevision['requiring_revision'], 'a Task Giver Mistake must not show as a revision the assignee needed');
        $this->assertNull($assigneeRevision['revision_rate_kpi']);
    }

    /** Only "Task Giver Mistake" counts here — every other reason is not the giver's fault either. */
    public function test_other_reason_categories_do_not_count_against_the_giver(): void
    {
        $giver    = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, $assignee, '2026-09-05', ['Employee Mistake']);
        $this->taskGivenBy($giver, $assignee, '2026-09-06', ['Client Requested']);
        $this->taskGivenBy($giver, $assignee, '2026-09-07', ['Scope Change']);
        $this->taskGivenBy($giver, $assignee, '2026-09-08', ['Management Requested']);

        $result = $this->quality($giver);

        $this->assertSame(4, $result['total_given']);
        $this->assertSame(4, $result['clean_first_time']);
        $this->assertSame(0, $result['flawed']);
        $this->assertSame(100.0, $result['pct']);
    }

    public function test_the_rate_is_the_share_of_clean_tasks(): void
    {
        $giver    = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, $assignee, '2026-09-01'); // clean
        $this->taskGivenBy($giver, $assignee, '2026-09-02'); // clean
        $this->taskGivenBy($giver, $assignee, '2026-09-03'); // clean
        $this->taskGivenBy($giver, $assignee, '2026-09-04', ['Task Giver Mistake']); // flawed

        $result = $this->quality($giver);

        $this->assertSame(4, $result['total_given']);
        $this->assertSame(3, $result['clean_first_time']);
        $this->assertSame(1, $result['flawed']);
        $this->assertSame(75.0, $result['pct']);
    }

    public function test_only_tasks_given_this_period_count(): void
    {
        $giver    = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, $assignee, '2026-08-28'); // last month
        $this->taskGivenBy($giver, $assignee, '2026-09-05'); // this month
        $this->taskGivenBy($giver, $assignee, '2026-10-01'); // next month

        $result = $this->quality($giver);

        $this->assertSame(1, $result['total_given']);
    }

    public function test_a_task_with_multiple_revisions_only_one_of_which_is_the_givers_fault_still_counts_as_flawed_once(): void
    {
        $giver    = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, $assignee, '2026-09-05', ['Employee Mistake', 'Task Giver Mistake']);

        $result = $this->quality($giver);

        $this->assertSame(1, $result['total_given']);
        $this->assertSame(1, $result['flawed']);
    }

    public function test_batch_scoring_matches_scoring_one_by_one(): void
    {
        $giverA = User::factory()->create(['is_active' => true]);
        $giverB = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giverA, $assignee, '2026-09-05');
        $this->taskGivenBy($giverB, $assignee, '2026-09-06', ['Task Giver Mistake']);

        $one = [$this->quality($giverA), $this->quality($giverB)];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$giverA, $giverB]), self::PERIOD);

        $this->assertSame($one, [
            $batched->taskGivingQuality($giverA, self::PERIOD),
            $batched->taskGivingQuality($giverB, self::PERIOD),
        ]);
    }

    public function test_it_counts_in_the_final_score_with_its_weight(): void
    {
        \App\Models\KpiWeightConfig::create([
            'scope_type' => \App\Models\KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 0, 'on_time_weight' => 0, 'revision_weight' => 0,
            'sales_weight' => 0, 'satisfaction_weight' => 0, 'client_care_weight' => 0,
            'daily_target_weight' => 0, 'output_volume_weight' => 0, 'task_giving_weight' => 100,
        ]);
        $giver = User::factory()->create(['is_active' => true]);
        $this->taskGivenBy($giver, User::factory()->create(['is_active' => true]), '2026-09-05');

        $result = app(PerformanceCalculationService::class)->finalScore($giver, self::PERIOD);

        $this->assertSame(['task_giving' => 100.0], $result['weights_used']);
        $this->assertSame(100.0, $result['final_score']);
    }
}
