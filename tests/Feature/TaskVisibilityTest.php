<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Who sees which tasks, and who may manage them.
 *
 * Admins and anyone granted "manage all tasks" see and manage every task.
 * Everyone else sees only the tasks they created or that were assigned to
 * them. "manage tasks" lets them create tasks and edit or delete the ones they
 * created — it does not open anyone else's.
 */
class TaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');

        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user->fresh();
    }

    private function task(?User $assignee, User $creator, string $title = 'Task'): Task
    {
        $task = Task::create([
            'title' => $title, 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'created_by' => $creator->id,
        ]);

        if ($assignee) {
            $task->assignees()->sync([$assignee->id]);
        }

        return $task;
    }

    /** @return array{0:User,1:User,2:User,3:Task,4:Task,5:Task} me, colleague, manager, mine-assigned, mine-created, not-mine */
    private function world(): array
    {
        $me        = $this->user('view tasks');
        $colleague = $this->user('view tasks');
        $manager   = $this->user('view tasks', 'manage tasks', 'manage all tasks');

        return [
            $me, $colleague, $manager,
            $this->task($me, $manager, 'Assigned to me'),
            $this->task($colleague, $me, 'Created by me'),
            $this->task($colleague, $manager, 'Somebody else'),
        ];
    }

    private function titlesListedFor(User $user): array
    {
        return collect($this->actingAs($user)->getJson(route('tasks.index'), self::AJAX)->assertOk()->json('data'))
            ->map(fn ($row) => $row['title'])
            ->sort()->values()->all();
    }

    // ── Seeing ───────────────────────────────────────────────────────────

    public function test_a_staff_member_lists_only_what_they_created_or_were_given(): void
    {
        [$me] = $this->world();

        $this->assertSame(['Assigned to me', 'Created by me'], $this->titlesListedFor($me));
    }

    public function test_someone_with_manage_all_tasks_lists_everything(): void
    {
        [, , $manager] = $this->world();

        $this->assertSame(['Assigned to me', 'Created by me', 'Somebody else'], $this->titlesListedFor($manager));
    }

    /**
     * The reported problem: Sales and Support hold 'manage tasks' to hand out
     * work, and it used to open every task in the company to them.
     */
    public function test_manage_tasks_alone_does_not_open_anyone_elses_tasks(): void
    {
        [$me, $colleague, $manager] = $this->world();
        $lead = $this->user('view tasks', 'manage tasks');
        $this->task($lead, $manager, 'Given to the lead');
        $this->task($colleague, $lead, 'Handed out by the lead');
        $private = $this->task($me, $colleague, 'Between two others');

        $this->assertSame(['Given to the lead', 'Handed out by the lead'], $this->titlesListedFor($lead));
        $this->actingAs($lead)->getJson(route('tasks.show', $private))->assertForbidden();
        $this->actingAs($lead)->getJson(route('tasks.index'), self::AJAX)->assertJsonPath('counts.total', 2);
    }

    public function test_manage_tasks_edits_and_deletes_only_what_you_created(): void
    {
        [$me, $colleague, $manager] = $this->world();
        $lead = $this->user('view tasks', 'manage tasks');
        $handedOut = $this->task($colleague, $lead, 'Handed out by the lead');
        $givenToLead = $this->task($lead, $manager, 'Given to the lead');

        $payload = ['title' => 'Renamed', 'priority' => 'High', 'status' => 'Pending', 'type' => 'Other', 'assignee_ids' => [$colleague->id]];

        $this->actingAs($lead)->putJson(route('tasks.update', $handedOut), $payload)->assertOk();
        $this->assertSame('Renamed', $handedOut->fresh()->title);

        // Being assigned a task is not a licence to rewrite or delete it.
        $this->actingAs($lead)->putJson(route('tasks.update', $givenToLead), $payload)->assertForbidden();
        $this->actingAs($lead)->deleteJson(route('tasks.destroy', $givenToLead))->assertForbidden();

        // The list offers Edit/Delete only where they would work.
        $rows = collect($this->actingAs($lead)->getJson(route('tasks.index'), self::AJAX)->json('data'))->keyBy('title');
        $this->assertStringContainsString('task-edit', $rows['Renamed']['actions']);
        $this->assertStringNotContainsString('task-edit', $rows['Given to the lead']['actions']);
        $this->assertStringNotContainsString('task-delete', $rows['Given to the lead']['actions']);

        $this->actingAs($lead)->deleteJson(route('tasks.destroy', $handedOut))->assertOk();
    }

    public function test_the_list_opens_newest_first(): void
    {
        [, , $manager] = $this->world();
        $newest = $this->task(null, $manager, 'Newest');

        $rows = $this->actingAs($manager)
            ->getJson(route('tasks.index', ['order' => [['column' => 0, 'dir' => 'desc']], 'columns' => [['data' => 'number', 'name' => 'id', 'orderable' => 'true', 'searchable' => 'false']]]), self::AJAX)
            ->json('data');

        $this->assertSame('Newest', $rows[0]['title']);
        $this->assertStringContainsString('#' . $newest->id, $rows[0]['number']);
    }

    public function test_the_filter_counts_only_count_what_you_can_see(): void
    {
        [$me] = $this->world();

        $this->actingAs($me)->getJson(route('tasks.index'), self::AJAX)->assertJsonPath('counts.total', 2);
    }

    public function test_someone_elses_task_cannot_be_opened_by_id(): void
    {
        [$me, , , $assigned, $created, $other] = $this->world();

        $this->actingAs($me)->getJson(route('tasks.show', $assigned))->assertOk();
        $this->actingAs($me)->getJson(route('tasks.show', $created))->assertOk();
        $this->actingAs($me)->getJson(route('tasks.show', $other))->assertForbidden();
        $this->actingAs($me)->get(route('tasks.show', $other))->assertForbidden();
    }

    public function test_a_user_without_task_permissions_sees_nothing(): void
    {
        [, , , $assigned] = $this->world();
        $nobody = $this->user();

        $this->actingAs($nobody)->get(route('tasks.index'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('tasks.show', $assigned))->assertForbidden();
    }

    // ── Managing ─────────────────────────────────────────────────────────

    public function test_without_manage_tasks_you_cannot_edit_or_delete_even_your_own_tasks(): void
    {
        [$me, , $manager, $assigned, $created] = $this->world();

        $payload = ['title' => 'Renamed', 'priority' => 'High', 'status' => 'Pending', 'type' => 'Other'];

        $this->actingAs($me)->putJson(route('tasks.update', $created), $payload)->assertForbidden();
        $this->actingAs($me)->deleteJson(route('tasks.destroy', $assigned))->assertForbidden();

        $this->actingAs($manager)->deleteJson(route('tasks.destroy', $assigned))->assertOk();
        $this->assertSoftDeleted($assigned);
    }

    /**
     * Before anything has been submitted, there is nothing of the assignee's
     * to send back — only oversight/the creator can reopen it this early.
     * Once it is their own handed-in work (Submitted or Completed), the
     * assignee may request a revision on it too — see
     * TaskWorkflowTest::test_the_assignee_can_send_their_own_submitted_work_back_for_revision().
     */
    public function test_a_revision_request_on_unsubmitted_work_is_a_management_call(): void
    {
        [$me, , $manager, $assigned] = $this->world();

        $this->actingAs($me)
            ->postJson(route('tasks.revisions.store', $assigned), ['reason_category' => 'Employee Mistake'])
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson(route('tasks.revisions.store', $assigned), ['reason_category' => 'Employee Mistake'])
            ->assertOk();
    }

    public function test_comments_and_files_on_someone_elses_task_are_refused(): void
    {
        [$me, $colleague, , , , $other] = $this->world();

        $this->actingAs($me)
            ->postJson(route('tasks.comments.store', $other), ['comment' => 'Peeking'])
            ->assertForbidden();

        $comment = TaskComment::create(['task_id' => $other->id, 'user_id' => $me->id, 'comment' => 'Left before losing access']);
        $this->actingAs($me)->deleteJson(route('tasks.comments.destroy', [$other, $comment]))->assertForbidden();

        Storage::disk('local')->put('task-attachments/x/a.txt', 'secret');
        $file = TaskAttachment::create([
            'task_id' => $other->id, 'user_id' => $me->id, 'original_name' => 'a.txt', 'stored_name' => 'a.txt',
            'file_path' => 'task-attachments/x/a.txt', 'disk' => 'local', 'mime_type' => 'text/plain', 'file_size' => 6,
        ]);

        $this->actingAs($me)->get(route('tasks.attachments.download', [$other, $file]))->assertForbidden();
        $this->actingAs($me)->deleteJson(route('tasks.attachments.destroy', [$other, $file]))->assertForbidden();
    }

    /** A never-seeded permission must mean "no", not a server error. */
    public function test_a_missing_permission_row_is_a_refusal_not_a_crash(): void
    {
        [$me, , , $assigned] = $this->world();
        Permission::where('name', 'manage tasks')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($me->fresh())->deleteJson(route('tasks.destroy', $assigned))->assertForbidden();
        $this->actingAs($me->fresh())->getJson(route('tasks.index'), self::AJAX)->assertOk();
    }
}
