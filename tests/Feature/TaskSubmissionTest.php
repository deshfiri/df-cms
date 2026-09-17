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

    private function task(array $attributes = []): Task
    {
        $this->actingAs($this->manager);

        return app(TaskService::class)->create($attributes + [
            'title' => 'Poster', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->anika->id,
        ]);
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
        $this->assertSame(['submitted' => 4], TaskInvolvement::where('task_id', $task->id)->where('user_id', $this->anika->id)->value('breakdown'));
    }

    public function test_only_the_assignee_may_submit_and_is_told_why(): void
    {
        $task = $this->task();

        $this->submit($task, [], $this->manager)
            ->assertForbidden()
            ->assertJson(['message' => 'Only the person this task is assigned to can submit it.']);

        $this->assertSame('Pending', $task->fresh()->status);
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
        $stale = $task->fresh();            // loaded while still Pending
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

        $this->assertSame('Pending', $task->fresh()->status);
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
        $this->assertSame(['attachment_added' => 4, 'submitted' => 4], $mine->breakdown);
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

        $this->assertSame('Pending', $task->fresh()->status);
        $this->assertSame(0, TaskAttachment::where('task_id', $task->id)->count());
    }

    public function test_the_requirement_is_set_when_creating_or_editing_a_task(): void
    {
        $this->actingAs($this->manager)->postJson(route('tasks.store'), [
            'title' => 'Banner', 'priority' => 'High', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->anika->id, 'requires_attachment' => 1,
        ])->assertOk();

        $task = Task::where('title', 'Banner')->sole();
        $this->assertTrue($task->requires_attachment);

        $this->actingAs($this->manager)->putJson(route('tasks.update', $task), [
            'title' => 'Banner', 'priority' => 'High', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->anika->id, 'requires_attachment' => 0,
        ])->assertOk();

        $this->assertFalse($task->fresh()->requires_attachment);
    }
}
