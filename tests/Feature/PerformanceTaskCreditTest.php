<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
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

    /** @return array<int,int> ids of $count freshly-created clients */
    private function clients(int $count): array
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return collect(range(1, $count))->map(fn () => Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client ' . uniqid(), 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ])->id)->all();
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

    public function test_creating_a_task_earns_no_credit_but_reviewing_it_earns_a_full_share(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $this->handedOverTask($manager, $anika, $bashir);

        $this->actingAs($manager);
        $this->tasks->addComment($this->create($manager, $anika), 'Use the new logo');

        $result = $this->score()->finalScore($manager, self::PERIOD);
        // Only the reviewed task counts — it's the manager's own full,
        // independent share, on top of whoever did the work, not instead of it.
        $this->assertSame(1, $result['components']['taskCompletion']['total']);
        $this->assertSame(100.0, $result['scores']['task_completion']);

        $credit = collect($this->score()->taskCredit($manager, self::PERIOD));
        $this->assertCount(2, $credit, 'Both tasks the manager touched appear in the audit trail.');
        $this->assertSame(['reviewer', 'creator'], $credit->pluck('role')->all());
        $this->assertSame([true, false], $credit->pluck('counted')->all());
        $this->assertSame([1.0, 0.0], $credit->pluck('share')->all());
    }

    public function test_the_reviewers_own_full_share_never_reduces_the_assignees(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $done    = $this->create($manager, $anika);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($done, $anika, 'In Progress');
        $this->tasks->submitForReview($done->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($done->fresh(), $manager, true);

        // The assignee keeps the exact same full share as before this change...
        $this->assertSame(1, $this->score()->taskCompletion($anika, self::PERIOD)['total']);
        $this->assertSame(100.0, $this->score()->taskCompletion($anika, self::PERIOD)['completion_pct']);

        // ...and the reviewer independently earns their own full share of the
        // very same task, on top rather than carved out of Anika's.
        $managers = $this->score()->taskCompletion($manager, self::PERIOD);
        $this->assertSame(1, $managers['total']);
        $this->assertSame(100.0, $managers['completion_pct']);
    }

    public function test_sending_a_task_back_still_earns_the_reviewer_credit(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, false); // sent back, not accepted

        $credit = collect($this->score()->taskCredit($manager, self::PERIOD));
        $this->assertSame('reviewer', $credit->first()['role']);
        $this->assertSame(1.0, $credit->first()['share'], 'Sending it back is still reviewing it.');
    }

    /**
     * The load-bearing rule this change exists to protect: a reviewer who
     * catches a real mistake and sends it back must never see that show up
     * as a mistake on their OWN revision-rate KPI — only the assignee whose
     * work actually needed fixing is scored on it.
     */
    public function test_a_reviewers_own_revision_rate_never_includes_a_task_they_only_reviewed(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, false, ['reason_category' => 'Employee Mistake']);

        // Anika's own work needed fixing — this correctly counts against her.
        $anikas = $this->score()->revisionRate($anika, self::PERIOD);
        $this->assertSame(1, $anikas['total_submitted']);
        $this->assertSame(1, $anikas['requiring_revision']);

        // The manager reviewed (and rightly rejected) it — that must not
        // look like a mistake, or count at all, on the manager's own rate.
        $managers = $this->score()->revisionRate($manager, self::PERIOD);
        $this->assertSame(0, $managers['total_submitted'], 'Nothing the manager submitted themselves, so nothing to rate.');
        $this->assertSame(0, $managers['requiring_revision']);
    }

    public function test_a_reviewers_credit_also_counts_toward_on_time_delivery(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika, ['due_date' => '2026-09-20']);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);
        $this->actingAs($manager);
        $this->travelTo(Carbon::parse('2026-09-18 10:00:00'));
        $this->tasks->review($task->fresh(), $manager, true);

        $managers = $this->score()->onTimeCompletion($manager, self::PERIOD);
        $this->assertSame(1, $managers['total_completed']);
        $this->assertSame(100.0, $managers['on_time_rate']);
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

    // ── Client bonus (Task Completion, On-Time Delivery, Revision Rate) ──

    public function test_a_completed_task_linked_to_two_clients_counts_double(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $done    = $this->create($manager, $anika, ['client_ids' => $this->clients(2)]);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($done, $anika, 'In Progress');
        $this->tasks->submitForReview($done->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($done->fresh(), $manager, true);

        $result = $this->score()->taskCompletion($anika, self::PERIOD);

        $this->assertSame(1, $result['total'], 'still plainly one task');
        $this->assertSame(2.0, $result['credited_total'], 'but worth double toward Task Completion');
        $this->assertSame(2.0, $result['credited_completed']);
        $this->assertSame(100.0, $result['completion_pct']);
    }

    public function test_a_completed_task_linked_to_three_clients_counts_triple(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $done    = $this->create($manager, $anika, ['client_ids' => $this->clients(3)]);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($done, $anika, 'In Progress');
        $this->tasks->submitForReview($done->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($done->fresh(), $manager, true);

        $this->assertSame(3.0, $this->score()->taskCompletion($anika, self::PERIOD)['credited_total']);
    }

    public function test_the_multiplier_scales_each_assignees_own_share_of_a_shared_task(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $task    = $this->create($manager, $anika, ['client_ids' => $this->clients(2)]);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->uploadAttachment($task->fresh(), UploadedFile::fake()->create('draft.pdf', 10));
        $this->actingAs($manager);
        $this->tasks->update($task->fresh(), ['assignee_ids' => [$bashir->id]]);
        $this->actingAs($bashir);
        $this->tasks->submitForReview($task->fresh(), $bashir);
        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, true);

        // Established by test_shared_work_is_credited_in_proportion: Anika's
        // plain share is 4/9, Bashir's is 5/9 — each doubled by the 2-client task.
        $this->assertEqualsWithDelta((4 / 9) * 2, $this->score()->taskCompletion($anika, self::PERIOD)['credited_total'], 0.001);
        $this->assertEqualsWithDelta((5 / 9) * 2, $this->score()->taskCompletion($bashir, self::PERIOD)['credited_total'], 0.001);
    }

    public function test_the_multiplier_also_applies_to_on_time_completion(): void
    {
        $anika = $this->user('Anika');

        // On time, 3 clients — weighs 3x among the on-time credit.
        $onTimeMulti = Task::create([
            'title' => 'On time multi', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $onTimeMulti->assignees()->sync([$anika->id]);
        $onTimeMulti->clients()->sync($this->clients(3));

        // Late, no client — weighs 1x among the late credit.
        $lateSolo = Task::create([
            'title' => 'Late solo', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-05', 'completion_date' => '2026-09-08',
        ]);
        $lateSolo->assignees()->sync([$anika->id]);

        // 3 credited on-time of 4 total (3 + 1) → 75%, not the 50% a plain
        // 1-vs-1 count would give.
        $this->assertSame(75.0, $this->score()->onTimeCompletion($anika, self::PERIOD)['on_time_rate']);
    }

    public function test_the_multiplier_also_applies_to_revision_rate(): void
    {
        $anika = $this->user('Anika');

        $clean = Task::create([
            'title' => 'Clean multi', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $clean->assignees()->sync([$anika->id]);
        $clean->clients()->sync($this->clients(3));

        $flawed = Task::create([
            'title' => 'Flawed solo', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $flawed->assignees()->sync([$anika->id]);
        \App\Models\TaskRevision::create([
            'task_id' => $flawed->id, 'requested_by' => $anika->id,
            'reason_category' => 'Employee Mistake', 'previous_status' => 'Completed',
        ]);

        // 1 mistake of 4 credited submitted (3 clean + 1 flawed) → 25%, not
        // the 50% a plain 1-vs-2 count would give.
        $this->assertEqualsWithDelta(25.0, $this->score()->revisionRate($anika, self::PERIOD)['revision_rate_kpi'], 0.01);
    }

    public function test_task_credit_reports_the_client_count_and_multiplier_per_task(): void
    {
        $anika = $this->user('Anika');
        $imported = Task::create([
            'title' => 'Imported', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $imported->assignees()->sync([$anika->id]);
        $imported->clients()->sync($this->clients(3));

        $row = $this->score()->taskCredit($anika, self::PERIOD)[0];
        $this->assertSame(3, $row['clients_count']);
        $this->assertSame(3, $row['client_multiplier']);
    }

    public function test_the_scorecard_shows_the_client_multiplier(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks', 'view performance');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika, ['client_ids' => $this->clients(2)]);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, true);

        $this->actingAs($manager)
            ->get(route('performance.show', ['user' => $anika, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('×2', false);
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

    /**
     * The Points column used to show the flat TaskInvolvement.points value,
     * which is always 0 for a pure reviewer — reading as "0 points, 100%
     * share" side by side, as if the two contradicted each other. It now
     * includes review_points too, so a reviewer's own earned points actually
     * show up next to their own full share.
     */
    public function test_the_scorecards_points_column_includes_review_points_not_just_work_points(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks', 'view performance');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        $this->actingAs($anika);
        $this->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $anika);
        $this->actingAs($manager);
        $this->tasks->review($task->fresh(), $manager, true);

        $credit = collect($this->score()->taskCredit($manager, self::PERIOD))->first();
        $this->assertSame(0.0, $credit['points'], 'the raw work-points value is still 0 for a reviewer');
        $this->assertSame(2.0, $credit['review_points']);

        $this->actingAs($manager)
            ->get(route('performance.show', ['user' => $manager, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Reviewer')
            ->assertSee('100%')
            ->assertSee('class="text-end">2</td>', false); // the reviewer's earned points, not the old flat 0
    }
}
