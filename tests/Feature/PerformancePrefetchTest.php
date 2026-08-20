<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientSatisfactionRating;
use App\Models\Payment;
use App\Models\SalesTarget;
use App\Models\Task;
use App\Models\TaskRevision;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scoring the whole team used to cost six queries per person, so the scoreboard
 * grew linearly with the payroll. It now loads the cohort once.
 *
 * The point of these tests is that the shortcut changed only the number of
 * queries: every score must come out exactly as it did when each employee was
 * scored on their own.
 */
class PerformancePrefetchTest extends TestCase
{
    use RefreshDatabase;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        // salesAchievement derives "days remaining" from now() to sub-second
        // precision, so two calls a millisecond apart differ. Freeze the clock
        // and the comparison measures the code, not the wall time.
        $this->freezeTime();

        $this->period = now()->format('Y-m');
    }

    private function client(?User $manager = null): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
            'assigned_to' => $manager?->id,
        ]);
    }

    /** An employee with a bit of everything the scoreboard measures. */
    private function employeeWithWork(): User
    {
        $user   = User::factory()->create(['is_active' => true]);
        $client = $this->client($user);

        $due = now()->startOfMonth()->addDays(5);

        // Completed early, on time, and late.
        foreach ([[-2, 'Completed'], [0, 'Completed'], [3, 'Completed']] as [$offset, $status]) {
            Task::create([
                'title'           => 'Task ' . uniqid(),
                'client_id'       => $client->id,
                'assigned_to'     => $user->id,
                'created_by'      => $user->id,
                'status'          => $status,
                'priority'        => 'Medium',
                'due_date'        => $due->toDateString(),
                'completion_date' => $due->copy()->addDays($offset)->toDateString(),
            ]);
        }

        // Still open, and one that was sent back.
        $open = Task::create([
            'title'       => 'Open work',
            'client_id'   => $client->id,
            'assigned_to' => $user->id,
            'created_by'  => $user->id,
            'status'      => 'In Progress',
            'priority'    => 'High',
            'due_date'    => $due->toDateString(),
        ]);

        TaskRevision::create([
            'task_id'         => $open->id,
            'requested_by'    => $user->id,
            'reason_category' => 'Employee Mistake',
            'previous_status' => 'In Progress',
            'note'            => 'Wrong figures',
        ]);

        SalesTarget::create([
            'user_id'       => $user->id,
            'period'        => $this->period,
            'target_amount' => 10000,
        ]);

        Payment::create([
            'client_id'    => $client->id,
            'amount'       => 2500,
            'status'       => 'Paid',
            'payment_date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'created_by'   => $user->id,
        ]);

        ClientSatisfactionRating::create([
            'client_id'   => $client->id,
            'employee_id' => $user->id,
            'rating'      => 5,
            'source_type' => 'Manual',
        ]);

        return $user->fresh();
    }

    public function test_prefetched_scores_are_identical_to_scoring_one_by_one(): void
    {
        $users = collect([$this->employeeWithWork(), $this->employeeWithWork(), $this->employeeWithWork()]);

        // Scored individually — a fresh service each time, so nothing is shared.
        $individually = $users->map(function (User $user) {
            return app(PerformanceCalculationService::class)->finalScore($user, $this->period);
        })->all();

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch($users, $this->period);
        $together = $users->map(fn (User $user) => $batched->finalScore($user, $this->period))->all();

        $this->assertEquals($individually, $together);
        // And the fixture is actually exercising the maths, not comparing nulls.
        $this->assertNotNull($individually[0]['final_score']);
    }

    public function test_the_cohort_load_does_not_grow_with_headcount(): void
    {
        $users = collect(range(1, 6))->map(fn () => $this->employeeWithWork());

        $service = app(PerformanceCalculationService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->prefetch($users, $this->period);
        $users->each(fn (User $user) => $service->finalScore($user, $this->period));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Six employees at six queries each would be 36 plus settings lookups.
        // The prefetch is a fixed handful, and roles are resolved in memory.
        $this->assertLessThan(
            20,
            $count,
            "Scoring 6 employees took {$count} queries — the cohort prefetch is not being used."
        );
    }

    public function test_an_employee_with_no_work_still_scores_the_same_either_way(): void
    {
        $idle = User::factory()->create(['is_active' => true]);

        $alone = app(PerformanceCalculationService::class)->finalScore($idle, $this->period);

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$idle]), $this->period);

        $this->assertEquals($alone, $batched->finalScore($idle, $this->period));
        $this->assertNull($alone['final_score']);
    }

    public function test_a_period_that_was_not_prefetched_still_queries_for_itself(): void
    {
        $user = $this->employeeWithWork();

        $service = app(PerformanceCalculationService::class);
        $service->prefetch(collect([$user]), $this->period);

        // Asking about a different month must not silently reuse this month's rows.
        $other = now()->subMonths(2)->format('Y-m');

        $this->assertEquals(
            app(PerformanceCalculationService::class)->finalScore($user, $other),
            $service->finalScore($user, $other)
        );
    }

    public function test_revenue_from_a_deleted_client_is_excluded_either_way(): void
    {
        $user   = $this->employeeWithWork();
        $client = $this->client($user);

        Payment::create([
            'client_id'    => $client->id,
            'amount'       => 9999,
            'status'       => 'Paid',
            'payment_date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'created_by'   => $user->id,
        ]);

        $client->delete();

        $alone = app(PerformanceCalculationService::class)->salesAchievement($user, $this->period);

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$user]), $this->period);

        // The single-user path used whereHas(), which honours the soft delete;
        // the batched join has to do the same by hand.
        $this->assertEquals($alone, $batched->salesAchievement($user, $this->period));
        $this->assertSame(2500.0, $alone['achieved']);
    }
}
