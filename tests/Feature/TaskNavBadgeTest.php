<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Tasks badge in the sidebar: what is waiting on you.
 */
class TaskNavBadgeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-17 10:00:00'));
        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->me   = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view tasks')->fresh();
        $this->boss = tap(User::factory()->create(['is_active' => true]))->givePermissionTo(['view tasks', 'manage tasks'])->fresh();
    }

    private function task(User $assignee, User $creator, string $status, array $extra = []): Task
    {
        return Task::create($extra + [
            'title' => 'Task ' . uniqid(), 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'assigned_to' => $assignee->id, 'created_by' => $creator->id,
        ]);
    }

    public function test_it_counts_open_work_overdue_work_and_reviews_waiting_on_me(): void
    {
        $this->task($this->me, $this->boss, 'Pending');
        $this->task($this->me, $this->boss, 'In Progress', ['due_date' => '2026-09-10']);            // overdue
        $this->task($this->me, $this->boss, 'On Hold', ['due_at' => '2026-09-17 09:00:00']);         // overdue by the hour
        $this->task($this->me, $this->boss, 'In Progress', ['due_date' => '2026-09-17']);            // due today — not late
        $this->task($this->me, $this->boss, 'Submitted');                                            // handed in — not mine to do
        $this->task($this->me, $this->boss, 'Completed');
        $this->task($this->me, $this->boss, 'Cancelled');
        $this->task($this->boss, $this->me, 'Submitted');                                            // handed in to me
        $this->task($this->boss, $this->me, 'In Progress');                                          // theirs to do
        $this->task($this->boss, $this->boss, 'Pending');                                            // nothing to do with me

        $this->actingAs($this->me)->getJson(route('tasks.nav-count'))
            ->assertOk()
            ->assertExactJson(['open' => 4, 'overdue' => 2, 'to_review' => 1, 'total' => 5]);
    }

    public function test_the_sidebar_shows_the_badge_and_turns_red_when_something_is_late(): void
    {
        $this->task($this->me, $this->boss, 'Pending', ['due_date' => '2026-09-01']);
        $this->task($this->me, $this->boss, 'Pending');

        $this->actingAs($this->me)->get(route('my-work'))
            ->assertOk()
            ->assertSee('id="taskNavBadge"', false)
            ->assertSee('display:inline-flex', false)
            ->assertSee('var(--c-red)', false)
            ->assertSee('2 to do (1 overdue) · 0 to review', false)
            ->assertSee(route('tasks.nav-count'), false);
    }

    public function test_deleted_tasks_do_not_count(): void
    {
        $this->task($this->me, $this->boss, 'Pending')->delete();

        $this->actingAs($this->me)->getJson(route('tasks.nav-count'))->assertJson(['total' => 0]);
    }

    public function test_someone_without_tasks_gets_no_badge(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider)->getJson(route('tasks.nav-count'))->assertForbidden();
        $this->actingAs($outsider)->get(route('my-work'))->assertOk()->assertDontSee('id="taskNavBadge"', false);
    }
}
