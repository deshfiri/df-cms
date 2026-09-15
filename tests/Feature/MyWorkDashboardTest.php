<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\Task;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "My Work" — the department dashboard.
 *
 * It was still reading the retired client pipeline, so work sitting in the flow
 * engine never appeared; it only listed open tasks, so nothing completed or
 * submitted showed; and its "open tasks" tile counted a list capped at ten.
 */
class MyWorkDashboardTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->flow = app(FlowService::class);

        foreach (['submit-stage', 'view tasks', 'manage tasks', 'manage workflows'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'Design', 'guard_name' => 'web']);
    }

    /** A Design-team stage worker: exactly who sees "My Work". */
    private function worker(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Design');
        $user->givePermissionTo(['submit-stage', 'view tasks']);

        return $user->fresh();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage workflows')->fresh();
    }

    /** Brief → Review, with the given people on each stage. */
    private function flowWith(array $briefPeople, array $reviewPeople): array
    {
        $flow   = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $this->admin()->id]);
        $brief  = $flow->stages()->create(['name' => 'Brief', 'position' => 1]);
        $review = $flow->stages()->create(['name' => 'Review', 'position' => 2]);
        $brief->users()->sync(collect($briefPeople)->pluck('id'));
        $review->users()->sync(collect($reviewPeople)->pluck('id'));

        return [$flow->refresh(), $brief, $review];
    }

    private function item(Flow $flow, string $title): FlowItem
    {
        return $this->flow->createItem($flow, ['title' => $title], $this->admin());
    }

    private function task(User $assignee, User $creator, string $status, array $extra = []): Task
    {
        return Task::create($extra + [
            'title' => 'Task ' . uniqid(), 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'assigned_to' => $assignee->id, 'created_by' => $creator->id,
        ]);
    }

    // ── Workflow work ────────────────────────────────────────────────────

    public function test_workflow_items_show_as_mine_and_available(): void
    {
        $me       = $this->worker();
        $teammate = $this->worker();
        $reviewer = $this->worker();
        [$flow] = $this->flowWith([$me, $teammate], [$reviewer]);

        $claimed   = $this->item($flow, 'Logo for ACME');
        $available = $this->item($flow, 'Banner for Beta');
        $theirs    = $this->item($flow, 'Brochure someone else took');
        $this->flow->claim($claimed->fresh(), $me);
        $this->flow->claim($theirs->fresh(), $teammate);

        // An item at a stage I'm not on.
        $elsewhere = $this->item($flow, 'Already in review');
        $this->flow->claim($elsewhere->fresh(), $teammate);
        $this->flow->advance($elsewhere->fresh(), $teammate);

        $response = $this->actingAs($me)->get(route('dashboard'))->assertOk();

        $response->assertViewHas('flowMine', fn ($items) => $items->pluck('id')->all() === [$claimed->id]);
        $response->assertViewHas('flowAvailable', fn ($items) => $items->pluck('id')->all() === [$available->id]);
        $response->assertSee('Logo for ACME')
            ->assertSee('Banner for Beta')
            ->assertDontSee('Brochure someone else took')
            ->assertDontSee('Already in review');
    }

    public function test_someone_on_no_stage_is_told_why_the_queue_is_empty(): void
    {
        $this->actingAs($this->worker())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('flowParticipant', false)
            ->assertSee("You aren't on any workflow stage yet.", false);
    }

    public function test_handing_work_forward_counts_as_done_but_sending_it_back_does_not(): void
    {
        $me       = $this->worker();
        $reviewer = $this->worker();
        [$flow] = $this->flowWith([$me], [$reviewer]);

        $item = $this->item($flow, 'Poster');
        $this->flow->claim($item->fresh(), $me);
        $this->flow->advance($item->fresh(), $me);                      // Brief → Review, by me
        $this->flow->claim($item->fresh(), $reviewer);
        $this->flow->sendBack($item->fresh(), $reviewer, 'Wrong colours'); // Review → Brief

        $this->actingAs($me)->get(route('dashboard'))
            ->assertViewHas('flowDoneThisWeek', 1);

        $this->actingAs($reviewer)->get(route('dashboard'))
            ->assertViewHas('flowDoneThisWeek', 0);
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function test_the_open_task_count_is_the_real_total_not_the_list_length(): void
    {
        $me = $this->worker();
        $boss = $this->worker();
        foreach (range(1, 12) as $i) {
            $this->task($me, $boss, 'Pending');
        }

        $this->actingAs($me)->get(route('dashboard'))
            ->assertViewHas('openTaskCount', 12)
            ->assertViewHas('myTasks', fn ($list) => $list->count() === 10)
            ->assertSee('+2 more on the Tasks page');
    }

    public function test_completed_and_submitted_tasks_show(): void
    {
        $me   = $this->worker();
        $boss = $this->worker();

        $done      = $this->task($me, $boss, 'Completed', ['title' => 'Finished the flyer', 'completion_date' => today()]);
        $submitted = $this->task($me, $boss, 'Submitted', ['title' => 'Handed in the menu', 'submitted_at' => now()]);
        $this->task($me, $boss, 'Cancelled', ['title' => 'Dropped idea']);

        $response = $this->actingAs($me)->get(route('dashboard'))->assertOk();

        $response->assertViewHas('completedTaskCount', 1)
            ->assertViewHas('completedTasks', fn ($list) => $list->pluck('id')->all() === [$done->id])
            ->assertViewHas('submittedTaskCount', 1)
            ->assertViewHas('submittedTasks', fn ($list) => $list->pluck('id')->all() === [$submitted->id])
            ->assertViewHas('tasksDoneThisWeek', 1)
            ->assertViewHas('openTaskCount', 0)
            ->assertSee('Finished the flyer')
            ->assertSee('Handed in the menu')
            ->assertDontSee('Dropped idea');
    }

    public function test_work_handed_in_to_me_waits_under_to_review(): void
    {
        $me     = $this->worker();
        $junior = $this->worker();
        $this->task($junior, $me, 'Submitted', ['title' => 'Junior finished the banner', 'submitted_at' => now()]);

        $this->actingAs($me)->get(route('dashboard'))
            ->assertViewHas('toReviewCount', 1)
            ->assertSee('To review')
            ->assertSee('Junior finished the banner');
    }

    public function test_the_page_links_every_item_somewhere_useful(): void
    {
        $me = $this->worker();
        [$flow] = $this->flowWith([$me], [$this->worker()]);
        $item = $this->item($flow, 'Linked item');
        $task = $this->task($me, $this->worker(), 'In Progress');

        $this->actingAs($me)->get(route('dashboard'))
            ->assertSee(route('flow-items.show', $item), false)
            ->assertSee(route('tasks.index', ['task' => $task->id]), false)
            ->assertSee('data-id="' . $item->id . '"', false);   // Claim button
    }
}
