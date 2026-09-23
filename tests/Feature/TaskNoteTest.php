<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskInvolvement;
use App\Models\TaskNote;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Links and notes shared beside a task's files.
 */
class TaskNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $anika;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true, 'name' => 'Maya Manager']))->givePermissionTo(['view tasks', 'manage tasks', 'manage all tasks'])->fresh();
        $this->anika   = tap(User::factory()->create(['is_active' => true, 'name' => 'Anika Assignee']))->givePermissionTo('view tasks')->fresh();
    }

    private function task(): Task
    {
        $this->actingAs($this->manager);

        return app(TaskService::class)->create([
            'title' => 'Spring campaign poster', 'priority' => 'High', 'status' => 'Pending',
            'type' => 'Other', 'assignee_ids' => [$this->anika->id],
        ]);
    }

    public function test_the_assignee_shares_a_link_and_it_shows_beside_the_files(): void
    {
        $task = $this->task();

        $this->actingAs($this->anika)
            ->postJson(route('tasks.notes.store', $task), ['body' => 'https://drive.google.com/drive/folders/abc'])
            ->assertOk();

        $this->actingAs($this->anika)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Files &amp; links', false)
            ->assertSee('id="taskNoteForm"', false)
            ->assertSee('href="https://drive.google.com/drive/folders/abc"', false)
            ->assertSee('drive.google.com ·')
            ->assertSee('shared a link');
    }

    public function test_a_link_pasted_without_its_scheme_still_opens(): void
    {
        $note = new TaskNote(['body' => 'www.figma.com/file/xyz']);

        $this->assertTrue($note->is_link);
        $this->assertSame('https://www.figma.com/file/xyz', $note->link_url);
        $this->assertSame('figma.com', $note->link_host);
    }

    public function test_a_note_is_escaped_and_only_web_addresses_become_links(): void
    {
        $note = new TaskNote(['body' => "<script>alert(1)</script> final is here (see https://x.com/a). javascript:alert(2)"]);

        $this->assertFalse($note->is_link);
        $this->assertNull($note->link_url);
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; final is here (see <a href="https://x.com/a" target="_blank" rel="noopener noreferrer nofollow">https://x.com/a</a>). javascript:alert(2)',
            $note->body_html,
        );

        // A file name is not a web address.
        $this->assertFalse((new TaskNote(['body' => 'report.docx']))->is_link);
        $this->assertFalse((new TaskNote(['body' => 'javascript:alert(1)']))->is_link);
    }

    public function test_a_note_on_the_page_is_escaped(): void
    {
        $task = $this->task();
        $this->actingAs($this->anika)
            ->postJson(route('tasks.notes.store', $task), ['body' => '<img src=x onerror=alert(1)> uploaded to the shared drive'])
            ->assertOk();

        $this->actingAs($this->manager)->get(route('tasks.show', $task))
            ->assertSee('&lt;img src=x onerror=alert(1)&gt; uploaded to the shared drive', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('left a note');
    }

    public function test_an_empty_note_is_refused(): void
    {
        $task = $this->task();

        $this->actingAs($this->anika)
            ->postJson(route('tasks.notes.store', $task), ['body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body' => 'Paste a link or write a note first.']);
    }

    public function test_someone_not_on_the_task_cannot_share_to_it(): void
    {
        $task = $this->task();
        $stranger = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view tasks');

        $this->actingAs($stranger)
            ->postJson(route('tasks.notes.store', $task), ['body' => 'https://example.com'])
            ->assertForbidden();

        $this->assertSame(0, TaskNote::count());
    }

    public function test_only_its_author_or_a_moderator_removes_a_note(): void
    {
        $task = $this->task();
        $this->actingAs($this->manager);
        $mine = app(TaskService::class)->addNote($task, 'Brief is in the shared drive');

        $this->actingAs($this->anika)
            ->deleteJson(route('tasks.notes.destroy', [$task, $mine]))
            ->assertForbidden();

        $this->actingAs($this->anika);
        $hers = app(TaskService::class)->addNote($task, 'https://x.com/draft');

        $this->actingAs($this->anika)->deleteJson(route('tasks.notes.destroy', [$task, $hers]))->assertOk();
        $this->actingAs($this->manager)->deleteJson(route('tasks.notes.destroy', [$task, $mine]))->assertOk();

        $this->assertSame(0, TaskNote::count());
        $this->actingAs($this->manager)->get(route('tasks.show', $task))
            ->assertSee('removed a link')
            ->assertSee('removed a note');
    }

    public function test_a_note_from_another_task_is_not_reachable_through_this_one(): void
    {
        $task  = $this->task();
        $other = $this->task();
        $note  = app(TaskService::class)->addNote($other, 'elsewhere');

        $this->actingAs($this->manager)
            ->deleteJson(route('tasks.notes.destroy', [$task, $note]))
            ->assertNotFound();
    }

    public function test_sharing_earns_the_doer_a_capped_credit(): void
    {
        $task = $this->task();
        $this->actingAs($this->anika);
        foreach (range(1, 5) as $i) {
            app(TaskService::class)->addNote($task, "https://x.com/{$i}");
        }

        $row = TaskInvolvement::where('task_id', $task->id)->where('user_id', $this->anika->id)->first();

        $this->assertEquals(1.5, $row->breakdown['note_added']);
    }
}
