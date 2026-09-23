<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The My Work panel's numbers: workload now, and output per day, week and month
 * in the viewer's own time zone.
 */
class MyWorkStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['view tasks', 'manage tasks', 'view dashboard'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        // Thursday 17 Sep 2026, 02:00 UTC — already 08:00 in Dhaka.
        $this->travelTo(Carbon::parse('2026-09-17 02:00:00', 'UTC'));
    }

    private function user(string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo($permissions ?: ['view tasks'])->fresh();
    }

    private function task(User $assignee, User $creator, string $status, array $extra = []): Task
    {
        $task = Task::create($extra + [
            'title' => 'Task ' . uniqid(), 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'created_by' => $creator->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    private function stats(User $user, ?string $tz = null): array
    {
        return $this->actingAs($user)
            ->getJson(route('my-work.stats', array_filter(['tz' => $tz])))
            ->assertOk()
            ->json();
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->getJson(route('my-work.stats'))->assertUnauthorized();
    }

    public function test_the_current_workload_is_counted_by_state(): void
    {
        $me   = $this->user();
        $boss = $this->user('view tasks', 'manage tasks');

        $this->task($me, $boss, 'Pending');
        $this->task($me, $boss, 'On Hold');
        $this->task($me, $boss, 'In Progress');
        $this->task($me, $boss, 'In Progress', ['due_date' => '2026-09-10']);   // overdue
        $this->task($me, $boss, 'Submitted', ['submitted_at' => now()]);
        $this->task($me, $boss, 'Completed');
        $this->task($boss, $me, 'Submitted', ['submitted_at' => now()]);          // handed in to me
        $this->task($this->user(), $boss, 'Pending');                               // not mine

        $now = $this->stats($me)['now'];

        $this->assertSame(4, $now['assigned']);
        $this->assertSame(2, $now['active']);
        $this->assertSame(2, $now['pending']);
        $this->assertSame(1, $now['awaiting_review']);
        $this->assertSame(1, $now['to_review']);
        $this->assertSame(1, $now['overdue']);
        $this->assertSame(1, $now['completed_total']);
        $this->assertFalse($now['flow_participant']);
    }

    public function test_output_is_counted_per_period(): void
    {
        $me   = $this->user();
        $boss = $this->user('view tasks', 'manage tasks');

        foreach ([
            '2026-09-17 01:00:00', // today (UTC)
            '2026-09-15 09:00:00', // this week (Tuesday)
            '2026-09-03 09:00:00', // this month
            '2026-08-30 09:00:00', // last month — but still inside the 14-day chart
        ] as $at) {
            $this->task($me, $boss, 'Completed', ['completed_at' => $at]);
        }
        $this->task($this->user(), $boss, 'Completed', ['completed_at' => '2026-09-17 01:00:00']);  // someone else's

        $stats = $this->stats($me, 'UTC');

        $this->assertSame(1, $stats['periods']['today']['completed']);
        $this->assertSame(2, $stats['periods']['week']['completed']);
        $this->assertSame(3, $stats['periods']['month']['completed']);
        $this->assertSame('2026-09-14', $stats['period_start']['week']);
        $this->assertSame('2026-09-01', $stats['period_start']['month']);

        $this->assertCount(14, $stats['series']['labels']);
        $this->assertSame('2026-09-04', $stats['series']['labels'][0]);
        $this->assertSame('2026-09-17', end($stats['series']['labels']));
        $this->assertSame(4 - 2, array_sum($stats['series']['completed']), 'Only the two within the last 14 days are charted.');
    }

    public function test_today_is_the_viewers_today(): void
    {
        $me   = $this->user();
        $boss = $this->user('view tasks', 'manage tasks');

        // 20:00 UTC on the 16th is 02:00 on the 17th in Dhaka.
        $this->task($me, $boss, 'Completed', ['completed_at' => '2026-09-16 20:00:00']);

        $this->assertSame(1, $this->stats($me, 'Asia/Dhaka')['periods']['today']['completed']);
        $this->assertSame(0, $this->stats($me, 'UTC')['periods']['today']['completed']);

        $dhaka = $this->stats($me, 'Asia/Dhaka');
        $this->assertSame('Asia/Dhaka', $dhaka['timezone']);
        $this->assertSame(1, end($dhaka['series']['completed']));
    }

    public function test_an_unknown_time_zone_falls_back_quietly(): void
    {
        $me = $this->user();

        $this->assertSame(config('app.timezone'), $this->stats($me, 'Mars/Olympus_Mons')['timezone']);
    }

    public function test_submissions_and_new_assignments_come_from_the_history(): void
    {
        $me   = $this->user();
        $boss = $this->user('view tasks', 'manage tasks');
        $service = app(TaskService::class);

        $this->actingAs($boss);
        $first  = $service->create(['title' => 'A', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other', 'assignee_ids' => [(string) $me->id]]);
        // Created unassigned (nobody assigns work to themselves), then handed to me.
        $second = $service->create(['title' => 'B', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other', 'assignee_ids' => []]);
        $service->update($second, ['assignee_ids' => [$me->id]]);

        $this->actingAs($me);
        $service->changeWorkingStatus($first->fresh(), $me, 'In Progress');
        $service->submitForReview($first->fresh(), $me);

        $today = $this->stats($me, 'UTC')['periods']['today'];

        $this->assertSame(2, $today['received'], 'Assigned at creation (id stored as a string) and by reassignment.');
        $this->assertSame(1, $today['submitted']);
        $this->assertSame(0, $this->stats($boss, 'UTC')['periods']['today']['submitted']);
    }

    public function test_every_user_has_a_my_work_page_even_with_no_permissions(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);
        $boss   = $this->user('view tasks', 'manage tasks');
        $this->task($nobody, $boss, 'In Progress', ['title' => 'Count the stock']);

        $this->actingAs($nobody)->get(route('my-work'))
            ->assertOk()
            ->assertSee('id="myWorkPanel"', false)
            ->assertSee('Count the stock')
            ->assertSee(route('my-work'), false);   // the sidebar link

        $this->actingAs($nobody)->getJson(route('my-work.stats'))
            ->assertOk()
            ->assertJsonPath('now.active', 1);
    }

    public function test_the_page_lists_what_is_waiting_on_me(): void
    {
        $me   = $this->user('view tasks', 'manage tasks');
        $anika = $this->user();
        $this->task($anika, $me, 'Submitted', ['title' => 'Poster handed in', 'submitted_at' => now()]);
        $this->task($me, $anika, 'Submitted', ['title' => 'My report', 'submitted_at' => now()]);
        $this->task($anika, $me, 'Pending', ['title' => 'Not mine to do']);

        $this->actingAs($me)->get(route('my-work'))
            ->assertOk()
            ->assertViewHas('waitingOnMe', fn ($list) => $list->pluck('title')->all() === ['Poster handed in'])
            ->assertViewHas('handedIn', fn ($list) => $list->pluck('title')->all() === ['My report'])
            ->assertViewHas('openTasks', fn ($list) => $list->isEmpty())
            ->assertSee(route('tasks.show', Task::where('title', 'Poster handed in')->sole()), false);
    }

    /**
     * The executive dashboard includes the same partial, but renders with
     * MySQL-only date functions, so it is checked against MySQL rather than
     * here on SQLite.
     */
    public function test_the_team_dashboard_carries_the_panel(): void
    {
        $this->actingAs($this->user('view tasks', 'view dashboard'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="myWorkPanel"', false)
            ->assertSee(route('my-work.stats'), false);
    }
}
