<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\TaskInvolvement;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Handing a task in: who may, when, and with what.
 */
class TaskSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $anika;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))->givePermissionTo(['view tasks', 'manage tasks'])->fresh();
        $this->anika   = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view tasks')->fresh();
    }

    /** A task assigned to Anika — started, unless asked otherwise, since only started work can be handed in. */
    private function task(array $attributes = [], bool $started = true): Task
    {
        $this->actingAs($this->manager);

        $task = app(TaskService::class)->create($attributes + [
            'title' => 'Poster', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$this->anika->id],
        ]);

        if ($started) {
            $this->actingAs($this->anika);
            $task = app(TaskService::class)->changeWorkingStatus($task, $this->anika, 'In Progress');
        }

        return $task;
    }

    public function test_work_must_be_started_before_it_can_be_submitted(): void
    {
        $task = $this->task(started: false);

        $this->submit($task, ['note' => 'Done already'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Start work on this task before submitting it.']);
        $this->assertSame('Pending', $task->fresh()->status);

        // The list offers the button, but disabled, saying why.
        $row = $this->actingAs($this->anika)
            ->getJson(route('tasks.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->json('data.0');
        $this->assertStringContainsString('disabled', $row['actions']);
        $this->assertStringContainsString('Start work on this task before submitting it.', $row['actions']);
        $this->assertStringNotContainsString('task-submit', $row['actions']);

        // Put on hold straight from Pending is still not started.
        $this->actingAs($this->anika);
        app(TaskService::class)->changeWorkingStatus($task->fresh(), $this->anika, 'On Hold');
        $this->submit($task)->assertUnprocessable()->assertJsonValidationErrors('status');

        // Once started — even if paused afterwards — it can be handed in.
        app(TaskService::class)->changeWorkingStatus($task->fresh(), $this->anika, 'In Progress');
        app(TaskService::class)->changeWorkingStatus($task->fresh(), $this->anika, 'On Hold');
        $this->submit($task)->assertOk();
    }

    private function submit(Task $task, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->anika)
            ->post(route('tasks.submit', $task), $data, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    public function test_the_assignee_submits_with_a_note(): void
    {
        $task = $this->task();

        $this->submit($task, ['note' => 'Final version'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Submitted for review.', 'task' => ['status' => 'Submitted']])
            ->assertJsonPath('timer.state', 'submitted');

        $task->refresh();
        $this->assertSame('Submitted', $task->status);
        $this->assertNotNull($task->submitted_at);

        $activity = TaskActivity::where('task_id', $task->id)->where('event', 'submitted')->sole();
        $this->assertSame($this->anika->id, $activity->user_id);
        $this->assertSame(['note' => 'Final version'], $activity->meta);
        $this->assertSame(['started' => 2, 'submitted' => 4], TaskInvolvement::where('task_id', $task->id)->where('user_id', $this->anika->id)->value('breakdown'));
    }

    public function test_only_the_assignee_may_submit_and_is_told_why(): void
    {
        $task = $this->task();

        $this->submit($task, [], $this->manager)
            ->assertForbidden()
            ->assertJson(['message' => 'Only someone this task is assigned to can submit it.']);

        $this->assertSame('In Progress', $task->fresh()->status);
    }

    public function test_a_task_cannot_be_submitted_twice(): void
    {
        $task = $this->task();
        $this->submit($task)->assertOk();

        $this->submit($task)
            ->assertForbidden()
            ->assertJson(['message' => 'This task has already been submitted and is waiting for review.']);

        $this->assertSame(1, TaskActivity::where('task_id', $task->id)->where('event', 'submitted')->count());
    }

    public function test_the_locked_row_refuses_a_submission_that_raced_past_the_policy(): void
    {
        $task  = $this->task();
        $stale = $task->fresh();            // loaded while still In Progress
        $this->submit($task)->assertOk();   // another tab submits first

        $this->actingAs($this->anika);
        try {
            app(TaskService::class)->submitForReview($stale, $this->anika);
            $this->fail('A second submission went through.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(1, TaskActivity::where('task_id', $task->id)->where('event', 'submitted')->count());
    }

    // ── Required files ───────────────────────────────────────────────────

    public function test_a_task_that_needs_a_file_cannot_be_submitted_without_one(): void
    {
        $task = $this->task(['requires_attachment' => true]);

        $this->submit($task, ['note' => 'Done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => 'This task needs a file with the submission.']);

        $this->assertSame('In Progress', $task->fresh()->status);
        $this->assertSame(0, TaskActivity::where('task_id', $task->id)->where('event', 'submitted')->count());
    }

    public function test_files_handed_in_with_the_submission_become_attachments(): void
    {
        $task = $this->task(['requires_attachment' => true]);

        $this->submit($task, ['files' => [
            UploadedFile::fake()->create('poster.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->image('preview.png'),
        ]])->assertOk();

        $attachments = TaskAttachment::where('task_id', $task->id)->orderBy('id')->get();
        $this->assertSame(['poster.pdf', 'preview.png'], $attachments->pluck('original_name')->all());
        $attachments->each(fn ($a) => Storage::disk('local')->assertExists($a->file_path));

        $submitted = TaskActivity::where('task_id', $task->id)->where('event', 'submitted')->sole();
        $this->assertSame($attachments->pluck('id')->all(), $submitted->meta['attachment_ids']);

        $mine = TaskInvolvement::where('task_id', $task->id)->where('user_id', $this->anika->id)->sole();
        $this->assertSame(['started' => 2, 'attachment_added' => 4, 'submitted' => 4], $mine->breakdown);
    }

    public function test_a_file_already_added_by_the_assignee_satisfies_the_requirement(): void
    {
        $task = $this->task(['requires_attachment' => true]);

        $this->actingAs($this->anika);
        app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->create('draft.pdf', 10));

        $this->submit($task)->assertOk();
    }

    public function test_the_brief_attached_by_the_requester_is_not_the_deliverable(): void
    {
        $task = $this->task(['requires_attachment' => true]);

        $this->actingAs($this->manager);
        app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->create('brief.pdf', 10));

        $this->submit($task)->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_rework_after_being_sent_back_needs_a_new_file(): void
    {
        $task = $this->task(['requires_attachment' => true]);
        $this->submit($task, ['files' => [UploadedFile::fake()->create('v1.pdf', 10)]])->assertOk();

        $this->travel(1)->minutes();
        $this->actingAs($this->manager);
        app(TaskService::class)->review($task->fresh(), $this->manager, false, ['reason_category' => 'Employee Mistake', 'note' => 'Wrong size']);
        $this->travel(1)->minutes();

        $this->submit($task)->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->submit($task, ['files' => [UploadedFile::fake()->create('v2.pdf', 10)]])->assertOk();
    }

    public function test_oversized_files_are_refused_before_anything_changes(): void
    {
        $task = $this->task();

        $this->submit($task, ['files' => [UploadedFile::fake()->create('huge.zip', 20481)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => 'Each file can be up to 20 MB.']);

        $this->assertSame('In Progress', $task->fresh()->status);
        $this->assertSame(0, TaskAttachment::where('task_id', $task->id)->count());
    }

    public function test_the_requirement_is_set_when_creating_or_editing_a_task(): void
    {
        $this->actingAs($this->manager)->postJson(route('tasks.store'), [
            'title' => 'Banner', 'priority' => 'High', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$this->anika->id], 'requires_attachment' => 1,
        ])->assertOk();

        $task = Task::where('title', 'Banner')->sole();
        $this->assertTrue($task->requires_attachment);

        $this->actingAs($this->manager)->putJson(route('tasks.update', $task), [
            'title' => 'Banner', 'priority' => 'High', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$this->anika->id], 'requires_attachment' => 0,
        ])->assertOk();

        $this->assertFalse($task->fresh()->requires_attachment);
    }

    // ── Nobody assigns work to themselves ────────────────────────────────

    public function test_nobody_can_assign_a_task_to_themselves(): void
    {
        $payload = ['title' => 'My errand', 'priority' => 'Low', 'status' => 'Pending', 'type' => 'Other'];

        $this->actingAs($this->manager)->postJson(route('tasks.store'), $payload + ['assignee_ids' => [$this->manager->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee_ids' => 'You can\'t assign a task to yourself.']);
        $this->assertSame(0, Task::where('title', 'My errand')->count());

        // Reassigning someone else's task to yourself is refused too.
        $task = $this->task(started: false);
        $this->actingAs($this->manager)->putJson(route('tasks.update', $task), $payload + ['assignee_ids' => [$this->manager->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_ids');
        $this->assertSame([$this->anika->id], $task->fresh()->assignees->pluck('id')->all());

        // The form offers yourself only as a disabled choice.
        $this->actingAs($this->manager)->get(route('tasks.index'))
            ->assertSee('(you — can\'t assign to yourself)', false);
    }

    public function test_an_older_task_already_assigned_to_you_can_still_be_edited(): void
    {
        $legacy = Task::create([
            'title' => 'Old errand', 'priority' => 'Low', 'status' => 'Pending', 'type' => 'Other',
            'created_by' => $this->manager->id,
        ]);
        $legacy->assignees()->sync([$this->manager->id]);

        $this->actingAs($this->manager)->putJson(route('tasks.update', $legacy), [
            'title' => 'Old errand, renamed', 'priority' => 'Low', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$this->manager->id],
        ])->assertOk();

        $this->assertSame('Old errand, renamed', $legacy->fresh()->title);
    }

    public function test_auto_assignment_never_picks_the_creator(): void
    {
        \App\Models\PerformanceSetting::current()->update(['auto_assign_enabled' => true]);
        User::query()->whereKeyNot($this->manager->id)->update(['is_active' => false]);

        $this->actingAs($this->manager);
        $task = app(TaskService::class)->create(['title' => 'Anyone', 'priority' => 'Low', 'status' => 'Pending', 'type' => 'Other']);

        $this->assertTrue($task->assignees->isEmpty(), 'With nobody else available it stays unassigned rather than landing on its creator.');
    }
}
