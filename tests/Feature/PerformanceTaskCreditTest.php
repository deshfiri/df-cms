<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task KPIs credit each person for the work they did on a task, not for
 * whoever happened to hold it last. See PerformanceCalculationService's
 * "Task credit" section and TaskInvolvementService for the formula.
 */
class PerformanceTaskCreditTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        Notification::fake();
        Storage::fake('local');
        foreach (['view tasks', 'manage tasks', 'view performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->tasks = app(TaskService::class);
    }

    private function user(string $name, string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true, 'name' => $name]))
            ->givePermissionTo($permissions ?: ['view tasks'])->fresh();
    }

    private function create(User $manager, ?User $assignee, array $extra = []): Task
    {
        $this->actingAs($manager);

        return $this->tasks->create($extra + [
            'title' => 'Brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => $assignee ? [$assignee->id] : [], 'due_date' => '2026-09-20',
        ]);
    }

    private function score(): PerformanceCalculationService
    {
        return app(PerformanceCalculationService::class);
    }

    /** Anika starts it and adds a draft (4 points), then Bashir finishes it (4 + 1 for holding). */
    private function handedOverTask(User $manager, User $anika, User $bashir): Task
    {
        $task = $this->create($manager, $anika);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->uploadAttachment($task->fresh(), UploadedFile::fake()->create('draft.pdf', 10));

        $this->actingAs($manager);
        $this->tasks->update($task->fresh(), ['assignee_ids' => [$bashir->id]]);

        $this->actingAs($bashir);
        $this->tasks->submitForReview($task->fresh(), $bashir);

        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, true);

        return $task->fresh();
    }

    public function test_a_task_done_alone_scores_exactly_as_before(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $done    = $this->create($manager, $anika);
        $this->create($manager, $anika);   // still pending

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($done, $anika, 'In Progress');
        $this->tasks->submitForReview($done->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($done->fresh(), $manager, true);

        $result = $this->score()->taskCompletion($anika, self::PERIOD);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['completed']);
        $this->assertSame(50.0, $result['completion_pct']);
        $this->assertSame(0, $result['shared']);
        $this->assertSame(2.0, $result['credited_total']);
    }

    public function test_a_task_that_only_passed_through_does_not_count(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $task    = $this->create($manager, $anika);

        $this->actingAs($manager);
        $this->tasks->update($task, ['assignee_ids' => [$bashir->id]]);

        $anikas = $this->score()->taskCompletion($anika, self::PERIOD);
        $this->assertSame(0, $anikas['total']);
        $this->assertNull($anikas['completion_pct'], 'An untouched hand-off must not count as unfinished work.');

        $this->assertSame(1, $this->score()->taskCompletion($bashir, self::PERIOD)['total']);
    }

    public function test_shared_work_is_credited_in_proportion(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $this->handedOverTask($manager, $anika, $bashir);
        $this->create($manager, $anika);   // Anika's own, not done

        $anikas = $this->score()->taskCompletion($anika, self::PERIOD);
        $this->assertSame(2, $anikas['total']);
        $this->assertSame(1, $anikas['shared']);
        $this->assertEqualsWithDelta(4 / 9 + 1, $anikas['credited_total'], 0.001);
        // (4/9 completed) ÷ (4/9 + 1 counted)
        $this->assertEqualsWithDelta(round((4 / 9) / (13 / 9) * 100, 2), $anikas['completion_pct'], 0.02);

        $bashirs = $this->score()->taskCompletion($bashir, self::PERIOD);
        $this->assertSame(100.0, $bashirs['completion_pct']);
        $this->assertEqualsWithDelta(5 / 9, $bashirs['credited_total'], 0.001);
    }

    public function test_creating_or_reviewing_a_task_earns_no_task_credit(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $this->handedOverTask($manager, $anika, $bashir);

        $this->actingAs($manager);
        $this->tasks->addComment($this->create($manager, $anika), 'Use the new logo');

        $result = $this->score()->finalScore($manager, self::PERIOD);
        $this->assertSame(0, $result['components']['taskCompletion']['total']);
        $this->assertNull($result['scores']['task_completion']);

        $credit = collect($this->score()->taskCredit($manager, self::PERIOD));
        $this->assertCount(2, $credit, 'The audit trail still lists them, to show why they did not count.');
        $this->assertFalse($credit->contains('counted', true));
        $this->assertSame(['reviewer', 'creator'], $credit->pluck('role')->all());
    }

    public function test_a_task_with_no_recorded_history_counts_in_full_for_its_assignee(): void
    {
        $anika = $this->user('Anika');
        $imported = Task::create([
            'title' => 'Imported', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $imported->assignees()->sync([$anika->id]);

        $result = $this->score()->taskCompletion($anika, self::PERIOD);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1.0, $result['credited_total']);
        $this->assertSame(100.0, $result['completion_pct']);
        $this->assertFalse($this->score()->taskCredit($anika, self::PERIOD)[0]['tracked']);
    }

    public function test_the_current_assignee_is_credited_even_if_reassigned_outside_the_task_service(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $task    = $this->create($manager, $anika);

        $task->assignees()->sync([$bashir->id]);   // no activity logged

        $this->assertSame(0, $this->score()->taskCompletion($anika, self::PERIOD)['total']);
        $this->assertSame(1.0, $this->score()->taskCompletion($bashir, self::PERIOD)['credited_total']);
    }

    // ── On-time delivery ─────────────────────────────────────────────────

    public function test_finishing_on_the_due_date_is_on_time(): void
    {
        $anika = $this->user('Anika');
        foreach (['2026-09-08' => '2026-09-06', '2026-09-09' => '2026-09-09', '2026-09-10' => '2026-09-12'] as $due => $done) {
            $task = Task::create([
                'title' => "Due {$due}", 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
                'due_date' => $due, 'completion_date' => $done,
            ]);
            $task->assignees()->sync([$anika->id]);
        }

        $result = $this->score()->onTimeCompletion($anika, self::PERIOD);

        $this->assertSame(1, $result['before_deadline']);
        $this->assertSame(1, $result['on_deadline'], 'Carbon 3 returns float day diffs; same-day must still match.');
        $this->assertSame(1, $result['after_deadline']);
        $this->assertSame(2.0, $result['avg_delay_days']);
        $this->assertEqualsWithDelta(66.67, $result['on_time_rate'], 0.01);
    }

    public function test_an_exact_deadline_is_missed_at_that_moment(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika, ['due_at' => '2026-09-15T12:00:00+00:00']);

        $this->travelTo(Carbon::parse('2026-09-15 18:00:00'));
        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task->fresh(), $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, true);

        $result = $this->score()->onTimeCompletion($anika, self::PERIOD);

        $this->assertSame(1, $result['after_deadline'], 'Six hours late on the same day is still late.');
        $this->assertSame(0.25, $result['avg_delay_days']);
        $this->assertSame(0.0, $result['on_time_rate']);
    }

    // ── Batch scoring and the scorecard ──────────────────────────────────

    public function test_prefetched_scores_match_individual_scores_for_shared_work(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $this->handedOverTask($manager, $anika, $bashir);
        $this->create($manager, $anika);
        $users = collect([$manager, $anika, $bashir]);

        $individually = $users->map(fn (User $u) => $this->score()->finalScore($u, self::PERIOD))->all();

        $batched = $this->score();
        $batched->prefetch($users, self::PERIOD);
        $together = $users->map(fn (User $u) => $batched->finalScore($u, self::PERIOD))->all();

        $this->assertEquals($individually, $together);
    }

    public function test_the_scorecard_shows_how_each_task_was_credited(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks', 'view performance');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $this->handedOverTask($manager, $anika, $bashir);

        $this->actingAs($manager)
            ->get(route('performance.show', ['user' => $anika, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Task credit')
            ->assertSee('Contributor')
            ->assertSee('Started +2')
            ->assertSee('Attachment Added +2')
            ->assertSee('44%');
    }
}
