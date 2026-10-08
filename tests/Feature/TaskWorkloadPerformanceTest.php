<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskRevision;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task Completion (and its like) measure a RATE, so an employee with 4
 * assigned tasks — all completed on time, zero revisions — used to score
 * identically to one with 14 tasks under the same perfect conditions.
 * Output Volume's new task_workload scope (PerformanceCalculationService::
 * outputVolume()) fixes that: it measures credited task volume relative to
 * department peers (falling back to a company-wide baseline for a small
 * department), weighted twice as heavily as Output Volume's other scopes,
 * with revision-affected work counting for half a clean task's credit
 * (workloadCreditOf()) — reusing revisionRate()'s own "Employee Mistake"
 * definition, never a new one.
 *
 * Every other KPI's own calculation is completely untouched by this; see
 * OutputVolumeScoringTest, PerformanceTaskCreditTest and the rest of the
 * Performance suite for proof those are unaffected.
 */
class TaskWorkloadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        Role::firstOrCreate(['name' => 'Engineering', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web']);
    }

    private function employee(string $name, string $role): User
    {
        return tap(User::factory()->create(['is_active' => true, 'name' => $name]))
            ->assignRole($role)->fresh();
    }

    private function score(): PerformanceCalculationService
    {
        return app(PerformanceCalculationService::class);
    }

    /** A clean, on-time, no-revision completed task for $assignee. */
    private function cleanTask(User $assignee, string $title = 'Task'): Task
    {
        $task = Task::create([
            'title' => $title, 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    /** A completed task that needed the assignee's own rework. */
    private function revisionAffectedTask(User $assignee, string $title = 'Reworked'): Task
    {
        $task = $this->cleanTask($assignee, $title);
        TaskRevision::create([
            'task_id' => $task->id, 'requested_by' => $assignee->id,
            'reason_category' => 'Employee Mistake', 'previous_status' => 'Completed',
        ]);

        return $task;
    }

    /** A completed-but-late task, no revision. */
    private function lateTask(User $assignee, string $title = 'Late'): Task
    {
        $task = Task::create([
            'title' => $title, 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-15',
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    public function test_14_clean_tasks_outscore_4_clean_tasks_in_the_same_department(): void
    {
        $filler = $this->employee('Filler', 'Engineering'); // keeps the department cohort above the minimum size
        $this->cleanTask($filler);

        $fourTasks = $this->employee('Four Tasks', 'Engineering');
        collect(range(1, 4))->each(fn ($i) => $this->cleanTask($fourTasks, "A$i"));

        $fourteenTasks = $this->employee('Fourteen Tasks', 'Engineering');
        collect(range(1, 14))->each(fn ($i) => $this->cleanTask($fourteenTasks, "B$i"));

        $a = $this->score()->finalScore($fourTasks, self::PERIOD);
        $b = $this->score()->finalScore($fourteenTasks, self::PERIOD);

        // Both are "perfect" on every rate KPI — task_workload is what must separate them.
        $this->assertSame(100.0, $a['scores']['task_completion']);
        $this->assertSame(100.0, $b['scores']['task_completion']);
        $this->assertGreaterThan($a['scores']['output_volume'], $b['scores']['output_volume']);
        $this->assertGreaterThan($a['final_score'], $b['final_score']);
        $this->assertSame(100.0, $b['components']['outputVolume']['scopes']['task_workload']['pct']);
    }

    public function test_substantially_higher_workload_can_overcome_a_small_quality_edge(): void
    {
        $filler = $this->employee('Filler', 'Engineering');
        $this->cleanTask($filler);

        $fourTasks = $this->employee('Four Perfect', 'Engineering');
        collect(range(1, 4))->each(fn ($i) => $this->cleanTask($fourTasks, "A$i"));

        // 20 tasks, 19 clean, 1 slightly late — a small, genuine quality dent.
        $twentyTasks = $this->employee('Twenty Mostly Perfect', 'Engineering');
        collect(range(1, 19))->each(fn ($i) => $this->cleanTask($twentyTasks, "B$i"));
        $this->lateTask($twentyTasks, 'B20');

        $a = $this->score()->finalScore($fourTasks, self::PERIOD);
        $b = $this->score()->finalScore($twentyTasks, self::PERIOD);

        // A is no longer automatically 100 either: Output Volume now applies
        // to A too, and A isn't the department's top workload — exactly the
        // point of the fix. B's one late task still costs B something on
        // On-Time Delivery, but B's far larger clean workload more than
        // makes up for it overall.
        $this->assertLessThan(100.0, $b['scores']['on_time'], 'The one late task should cost something on On-Time Delivery.');
        $this->assertGreaterThan($a['final_score'], $b['final_score'], 'The large workload gap should outweigh one late task out of 20.');
    }

    public function test_a_large_revision_heavy_late_workload_does_not_outrank_a_small_clean_one(): void
    {
        $filler = $this->employee('Filler', 'Engineering');
        $this->cleanTask($filler);

        $tenClean = $this->employee('Ten Clean', 'Engineering');
        collect(range(1, 10))->each(fn ($i) => $this->cleanTask($tenClean, "A$i"));

        $twentyFivePoor = $this->employee('Twenty Five Poor', 'Engineering');
        collect(range(1, 15))->each(fn ($i) => $this->revisionAffectedTask($twentyFivePoor, "B$i"));
        collect(range(16, 25))->each(fn ($i) => $this->lateTask($twentyFivePoor, "B$i"));

        $a = $this->score()->finalScore($tenClean, self::PERIOD);
        $b = $this->score()->finalScore($twentyFivePoor, self::PERIOD);

        $this->assertGreaterThan($b['final_score'], $a['final_score'], 'A small clean workload must outrank a large poor-quality one.');
    }

    public function test_same_workload_different_quality_the_better_quality_wins(): void
    {
        $filler = $this->employee('Filler', 'Engineering');
        $this->cleanTask($filler);

        $clean = $this->employee('Clean Ten', 'Engineering');
        collect(range(1, 10))->each(fn ($i) => $this->cleanTask($clean, "A$i"));

        $flawed = $this->employee('Flawed Ten', 'Engineering');
        collect(range(1, 10))->each(fn ($i) => $this->revisionAffectedTask($flawed, "B$i"));

        $cleanScore = $this->score()->finalScore($clean, self::PERIOD);
        $flawedScore = $this->score()->finalScore($flawed, self::PERIOD);

        $this->assertGreaterThan($flawedScore['final_score'], $cleanScore['final_score']);
    }

    public function test_same_quality_different_workload_the_higher_clean_workload_wins(): void
    {
        $filler = $this->employee('Filler', 'Engineering');
        $this->cleanTask($filler);

        $five = $this->employee('Five Clean', 'Engineering');
        collect(range(1, 5))->each(fn ($i) => $this->cleanTask($five, "A$i"));

        $fifteen = $this->employee('Fifteen Clean', 'Engineering');
        collect(range(1, 15))->each(fn ($i) => $this->cleanTask($fifteen, "B$i"));

        $fiveScore = $this->score()->finalScore($five, self::PERIOD);
        $fifteenScore = $this->score()->finalScore($fifteen, self::PERIOD);

        $this->assertGreaterThan($fiveScore['final_score'], $fifteenScore['final_score']);
    }

    public function test_zero_eligible_tasks_leaves_the_scope_out_with_no_division_by_zero(): void
    {
        $noTasks = $this->employee('No Tasks', 'Engineering');

        $result = $this->score()->outputVolume($noTasks, self::PERIOD);

        $this->assertNull($result, 'No workflow items, no client portfolio, no tasks — nothing to measure at all.');
    }

    public function test_a_small_department_falls_back_to_the_company_wide_baseline(): void
    {
        // Only 2 people in Marketing this period — below MIN_TASK_VOLUME_COHORT (3).
        $soloA = $this->employee('Marketing Solo A', 'Marketing');
        $this->cleanTask($soloA, 'M1');
        $soloB = $this->employee('Marketing Solo B', 'Marketing');
        collect(range(1, 2))->each(fn ($i) => $this->cleanTask($soloB, "M$i"));

        // A big Engineering department sets a much higher company-wide bar.
        collect(range(1, 4))->each(function ($n) {
            $peer = $this->employee("Engineer $n", 'Engineering');
            collect(range(1, 10))->each(fn ($i) => $this->cleanTask($peer, "E{$n}_$i"));
        });

        $result = $this->score()->outputVolume($soloA, self::PERIOD);

        // Compared against the company-wide max (10), not trivially against
        // Marketing's own max (2, which would hand Marketing Solo A an
        // unearned ~50%, or Solo B a meaningless 100%).
        $this->assertLessThan(20.0, $result['scopes']['task_workload']['pct']);
    }

    public function test_cohort_maxes_never_leak_across_departments(): void
    {
        collect(range(1, 3))->each(function ($n) {
            $peer = $this->employee("Engineer $n", 'Engineering');
            collect(range(1, 20))->each(fn ($i) => $this->cleanTask($peer, "E{$n}_$i"));
        });

        collect(range(1, 3))->each(function ($n) {
            $peer = $this->employee("Marketer $n", 'Marketing');
            $this->cleanTask($peer, "M{$n}_1");
        });

        $marketer = User::where('name', 'Marketer 1')->first();
        $result = $this->score()->outputVolume($marketer, self::PERIOD);

        // Marketing's own department max is 1 task, so a lone clean task
        // should score 100% within Marketing — Engineering's much larger
        // volumes must never be the baseline for a Marketing employee.
        $this->assertSame(100.0, $result['scopes']['task_workload']['pct']);
    }

    public function test_revision_affected_tasks_count_for_less_workload_but_not_zero_and_revision_rate_is_unaffected(): void
    {
        $filler = $this->employee('Filler', 'Engineering');
        $this->cleanTask($filler);

        $clean = $this->employee('All Clean', 'Engineering');
        collect(range(1, 10))->each(fn ($i) => $this->cleanTask($clean, "A$i"));

        $reworked = $this->employee('All Reworked', 'Engineering');
        collect(range(1, 10))->each(fn ($i) => $this->revisionAffectedTask($reworked, "B$i"));

        $cleanResult = $this->score()->finalScore($clean, self::PERIOD);
        $reworkedResult = $this->score()->finalScore($reworked, self::PERIOD);

        $cleanVolume = $cleanResult['components']['outputVolume']['scopes']['task_workload']['mine'];
        $reworkedVolume = $reworkedResult['components']['outputVolume']['scopes']['task_workload']['mine'];

        // Half credit per task (10 clean = 10.0, 10 reworked = 5.0), never zero.
        $this->assertEqualsWithDelta(10.0, $cleanVolume, 0.01);
        $this->assertEqualsWithDelta(5.0, $reworkedVolume, 0.01);
        $this->assertGreaterThan(0.0, $reworkedVolume);

        // Revision Rate itself must be completely unaffected by this scope existing.
        $this->assertSame(0.0, $cleanResult['components']['revision']['revision_rate_kpi']);
        $this->assertSame(100.0, $reworkedResult['components']['revision']['revision_rate_kpi']);
    }

    public function test_a_multi_client_task_does_not_single_handedly_become_the_cohort_max(): void
    {
        $filler = $this->employee('Filler', 'Engineering');
        $this->cleanTask($filler);

        $busy = $this->employee('Busy Peer', 'Engineering');
        collect(range(1, 10))->each(fn ($i) => $this->cleanTask($busy, "P$i"));

        $multiClient = $this->employee('Multi Client', 'Engineering');
        $task = $this->cleanTask($multiClient, 'Multi');
        $category = \App\Models\Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $clients = collect(range(1, 3))->map(fn ($i) => \App\Models\Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => "Client $i", 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ])->id);
        $task->clients()->sync($clients);

        $result = $this->score()->outputVolume($multiClient, self::PERIOD);

        // One 3-client task credits as 3 — still well short of the busy
        // peer's 10 clean tasks, so it must not become the cohort max.
        $this->assertLessThan(100.0, $result['scopes']['task_workload']['pct']);
        $this->assertEqualsWithDelta(3.0, $result['scopes']['task_workload']['mine'], 0.01);
    }
}
