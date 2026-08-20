<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowStage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskReviewed;
use App\Notifications\TaskSubmitted;
use App\Services\TaskDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Delegated work: someone on a workflow stage hands a task to a specific person
 * on the stage after theirs, that person submits it, and the requester accepts
 * it or sends it back.
 */
class TaskDelegationTest extends TestCase
{
    use RefreshDatabase;

    private Flow $flow;
    /** @var array<int,FlowStage> */
    private array $stages = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $creator = User::factory()->create(['is_active' => true]);
        $this->flow = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $creator->id]);

        foreach (['Design', 'Build', 'QA'] as $i => $name) {
            $this->stages[$i] = FlowStage::create([
                'flow_id'  => $this->flow->id,
                'name'     => $name,
                'position' => $i + 1,
            ]);
        }
    }

    /** A user working the given stage (0-indexed). */
    private function stageWorker(int $stage, array $permissions = ['view tasks']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);
        $this->stages[$stage]->users()->attach($user->id);

        return $user->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    private function payload(Client $client, User $assignee): array
    {
        return [
            'title'       => 'Cut the banner assets',
            'client_id'   => $client->id,
            'assigned_to' => $assignee->id,
            'priority'    => 'Medium',
            'status'      => 'Pending',
            'type'        => 'Other',
        ];
    }

    // ── Who can be delegated to ──────────────────────────────────────────

    public function test_a_stage_worker_may_assign_to_the_next_stage(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);

        $allowed = app(TaskDelegationService::class)->assignableUserIds($designer);

        $this->assertTrue($allowed->contains($builder->id));
        $this->assertFalse($allowed->contains($designer->id));
    }

    public function test_a_stage_worker_may_not_reach_past_the_next_stage(): void
    {
        $designer = $this->stageWorker(0);
        $tester   = $this->stageWorker(2);   // QA — two stages down

        $allowed = app(TaskDelegationService::class)->assignableUserIds($designer);

        $this->assertFalse($allowed->contains($tester->id));
    }

    public function test_the_last_stage_has_nobody_below_it(): void
    {
        $tester = $this->stageWorker(2);

        $this->assertFalse(app(TaskDelegationService::class)->canDelegate($tester));
    }

    public function test_manage_tasks_is_unrestricted(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo('manage tasks');

        // null means "no restriction", not "nobody".
        $this->assertNull(app(TaskDelegationService::class)->assignableUserIds($manager));
        $this->assertTrue(app(TaskDelegationService::class)->mayAssignTo($manager, $this->stageWorker(2)->id));
    }

    // ── Creating the delegated task ──────────────────────────────────────

    public function test_a_stage_worker_can_create_a_task_for_the_next_stage(): void
    {
        Notification::fake();

        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $client   = $this->client();

        $this->actingAs($designer)
            ->postJson(route('tasks.store'), $this->payload($client, $builder))
            ->assertOk();

        $task = Task::firstOrFail();
        $this->assertSame($builder->id, $task->assigned_to);
        $this->assertSame($designer->id, $task->created_by);
    }

    public function test_assigning_outside_the_next_stage_is_refused(): void
    {
        $designer = $this->stageWorker(0);
        $this->stageWorker(1);              // someone to delegate to, so the ask is allowed
        $tester   = $this->stageWorker(2);  // but this one is a stage too far
        $client   = $this->client();

        $this->actingAs($designer)
            ->postJson(route('tasks.store'), $this->payload($client, $tester))
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_to');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_someone_on_no_stage_and_without_the_permission_cannot_create_tasks(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->givePermissionTo('view tasks');

        $this->actingAs($outsider)
            ->postJson(route('tasks.store'), $this->payload($this->client(), $outsider))
            ->assertForbidden();
    }

    // ── Submit → review ──────────────────────────────────────────────────

    private function delegatedTask(User $from, User $to): Task
    {
        return Task::create([
            'title'       => 'Cut the banner assets',
            'client_id'   => $this->client()->id,
            'assigned_to' => $to->id,
            'created_by'  => $from->id,
            'status'      => 'In Progress',
            'priority'    => 'Medium',
        ]);
    }

    public function test_the_assignee_submits_and_the_requester_is_notified(): void
    {
        Notification::fake();

        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $task     = $this->delegatedTask($designer, $builder);

        $this->actingAs($builder)
            ->postJson(route('tasks.submit', $task), ['note' => 'Exported at 2x'])
            ->assertOk();

        $task->refresh();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->status);
        $this->assertNotNull($task->submitted_at);

        Notification::assertSentTo($designer, TaskSubmitted::class);
        Notification::assertNotSentTo($builder, TaskSubmitted::class);
    }

    public function test_nobody_else_can_submit_someone_elses_task(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $other    = $this->stageWorker(1);

        $task = $this->delegatedTask($designer, $builder);

        $this->actingAs($other)->postJson(route('tasks.submit', $task))->assertForbidden();
        $this->assertSame('In Progress', $task->fresh()->status);
    }

    public function test_accepting_completes_the_task_and_tells_the_assignee(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $task     = $this->delegatedTask($designer, $builder);

        $this->actingAs($builder)->postJson(route('tasks.submit', $task))->assertOk();

        Notification::fake();

        $this->actingAs($designer)
            ->postJson(route('tasks.review', $task), ['accept' => true, 'note' => 'Looks good'])
            ->assertOk();

        $task->refresh();
        $this->assertSame('Completed', $task->status);
        $this->assertNotNull($task->completion_date);

        Notification::assertSentTo($builder, TaskReviewed::class);
    }

    public function test_sending_it_back_reopens_it_and_records_a_revision(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $task     = $this->delegatedTask($designer, $builder);

        $this->actingAs($builder)->postJson(route('tasks.submit', $task))->assertOk();

        Notification::fake();

        $this->actingAs($designer)
            ->postJson(route('tasks.review', $task), [
                'accept'          => false,
                'reason_category' => 'Employee Mistake',
                'note'            => 'Wrong dimensions',
            ])
            ->assertOk();

        $task->refresh();
        // Back with the assignee, not stuck in limbo.
        $this->assertSame('In Progress', $task->status);
        $this->assertNull($task->submitted_at);
        $this->assertSame(1, $task->revisions()->count());

        Notification::assertSentTo($builder, TaskReviewed::class);
    }

    public function test_only_the_requester_reviews_it(): void
    {
        $designer  = $this->stageWorker(0);
        $builder   = $this->stageWorker(1);
        $bystander = $this->stageWorker(0);

        $task = $this->delegatedTask($designer, $builder);
        $this->actingAs($builder)->postJson(route('tasks.submit', $task))->assertOk();

        $this->actingAs($bystander)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertForbidden();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_a_task_manager_can_clear_a_stuck_review(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $manager  = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo('manage tasks');

        $task = $this->delegatedTask($designer, $builder);
        $this->actingAs($builder)->postJson(route('tasks.submit', $task))->assertOk();

        // So work never gets stuck behind someone who has left.
        $this->actingAs($manager)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertOk();

        $this->assertSame('Completed', $task->fresh()->status);
    }

    public function test_a_task_that_is_not_submitted_cannot_be_reviewed(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $task     = $this->delegatedTask($designer, $builder);

        $this->actingAs($designer)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertForbidden();
    }

    public function test_a_completed_task_cannot_be_resubmitted(): void
    {
        $designer = $this->stageWorker(0);
        $builder  = $this->stageWorker(1);
        $task     = $this->delegatedTask($designer, $builder);
        $task->update(['status' => 'Completed']);

        $this->actingAs($builder)->postJson(route('tasks.submit', $task))->assertForbidden();
    }
}
