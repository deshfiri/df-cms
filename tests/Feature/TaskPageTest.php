<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A task's own page, /tasks/{task}: what each person sees and can do there.
 */
class TaskPageTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $anika;
    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        foreach (['view tasks', 'manage tasks', 'view performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true, 'name' => 'Maya Manager']))->givePermissionTo(['view tasks', 'manage tasks'])->fresh();
        $this->anika   = tap(User::factory()->create(['is_active' => true, 'name' => 'Anika Assignee']))->givePermissionTo('view tasks')->fresh();
        $this->tasks   = app(TaskService::class);
    }

    private function task(array $attributes = []): Task
    {
        $this->actingAs($this->manager);

        return $this->tasks->create($attributes + [
            'title' => 'Spring campaign poster', 'description' => 'A3, brand colours', 'priority' => 'High',
            'status' => 'Pending', 'type' => 'Other', 'assigned_to' => $this->anika->id,
            'due_at' => now()->addDay()->setTime(15, 30)->toIso8601String(),
        ]);
    }

    public function test_the_assignee_gets_a_full_page_with_a_submit_button(): void
    {
        $task = $this->task(['requires_attachment' => true]);

        // Not started yet: Submit is there but disabled, and says why.
        $this->actingAs($this->anika)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewIs('tasks.show')
            ->assertSee('Spring campaign poster')
            ->assertSee('A3, brand colours')
            ->assertSee('Submit Task')
            ->assertDontSee('data-requires="1"', false)
            ->assertSee('Start work on this task before submitting it.')
            ->assertSee('Start work')
            ->assertSee('id="taskTimer"', false)
            ->assertSee('Maya Manager')
            ->assertSee('History')
            ->assertDontSee('id="tpEdit"', false)
            ->assertDontSee('id="tpDelete"', false)
            ->assertDontSee('work share');

        // Started: Submit is live, with its file requirement.
        $this->actingAs($this->anika);
        $this->tasks->changeWorkingStatus($task, $this->anika, 'In Progress');

        $this->actingAs($this->anika)->get(route('tasks.show', $task))
            ->assertSee('data-requires="1"', false)
            ->assertSee('This task needs a file with the submission')
            ->assertDontSee('Start work on this task before submitting it.');
    }

    public function test_the_timer_carries_the_servers_clock_and_deadline(): void
    {
        $task = $this->task();

        $timer = $this->actingAs($this->anika)->get(route('tasks.show', $task))->viewData('timer');

        $this->assertSame('not_started', $timer['state']);
        $this->assertSame($task->fresh()->due_at->toIso8601String(), $timer['due_at']);
        $this->assertTrue($timer['due_has_time']);
        $this->assertNotEmpty($timer['server_now']);
    }

    public function test_a_manager_can_edit_and_delete_but_has_nothing_to_submit(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('id="tpEdit"', false)
            ->assertSee('id="tpDelete"', false)
            ->assertSee('id="taskModal"', false)
            ->assertSee('work share')
            ->assertDontSee('Submit Task');
    }

    public function test_the_requester_reviews_from_the_page(): void
    {
        $task = $this->task();
        $this->actingAs($this->anika);
        $this->tasks->changeWorkingStatus($task, $this->anika, 'In Progress');
        $this->tasks->submitForReview($task->fresh(), $this->anika, 'Done');

        $this->actingAs($this->manager)->get(route('tasks.show', $task))
            ->assertSee('Review submission')
            ->assertSee('handed this in');

        $this->actingAs($this->anika)->get(route('tasks.show', $task))
            ->assertDontSee('Submit Task')
            ->assertSee('waiting for');
    }

    public function test_someone_not_on_the_task_cannot_open_it(): void
    {
        $task = $this->task();
        $stranger = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view tasks');

        $this->actingAs($stranger)->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_files_comments_and_history_are_on_the_page(): void
    {
        $task = $this->task();
        $this->actingAs($this->anika);
        $this->tasks->changeWorkingStatus($task, $this->anika, 'In Progress');
        $image = $this->tasks->uploadAttachment($task->fresh(), UploadedFile::fake()->image('draft.png'));
        $this->tasks->uploadAttachment($task->fresh(), UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'));
        $this->tasks->addComment($task->fresh(), '<script>alert(1)</script> first pass');

        $this->actingAs($this->anika)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('draft.png')
            ->assertSee('notes.pdf')
            ->assertSee(route('tasks.attachments.preview', [$task, $image]), false)
            ->assertSee('data-preview-src', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; first pass', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('changed the status')
            ->assertSee('Pending → In Progress')
            ->assertSee('added a file')
            ->assertSee('Put on hold');
    }

    public function test_opening_the_page_records_nothing(): void
    {
        $task = $this->task();
        $before = TaskActivity::count();

        $this->actingAs($this->anika)->get(route('tasks.show', $task))->assertOk();
        $this->actingAs($this->manager)->get(route('tasks.show', $task))->assertOk();

        $this->assertSame($before, TaskActivity::count());
    }

    public function test_the_timer_resync_is_light(): void
    {
        $task = $this->task();

        $this->actingAs($this->anika)
            ->getJson(route('tasks.show', ['task' => $task, 'timer_only' => 1]))
            ->assertOk()
            ->assertExactJson([
                'status' => 'Pending',
                'timer'  => $task->fresh()->timer(),
            ]);
    }

    public function test_the_list_links_each_task_to_its_page(): void
    {
        $task = $this->task();

        $row = $this->actingAs($this->anika)
            ->getJson(route('tasks.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data.0');

        $this->assertStringContainsString(route('tasks.show', $task), $row['title_link']);
        $this->assertStringContainsString(route('tasks.show', $task), $row['actions']);
        $this->assertStringContainsString('<time class="local-dt"', $row['due']);
    }

    public function test_the_list_page_renders_with_the_shared_dialogs(): void
    {
        $this->actingAs($this->manager)->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('id="taskModal"', false)
            ->assertDontSee('id="taskDetailModal"', false);
    }
}
