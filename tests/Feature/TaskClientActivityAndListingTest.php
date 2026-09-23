<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Two things a single-client Task used to give for free, now that a task can
 * have several clients at once:
 *
 *  - the global activity log (a client's own Activity tab reads this) has to
 *    keep showing task events, once per client the task is actually for —
 *    see TaskService::logForClients().
 *  - every list/detail screen that used to read $task->client has to read
 *    $task->clients (a collection) instead.
 */
class TaskClientActivityAndListingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view tasks', 'manage tasks', 'manage all tasks'])->fresh();
    }

    private function client(string $name = 'ACME Ltd'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => $name, 'brand_name' => $name,
            'category_id' => $category->id,
        ]);
    }

    // ── Activity log fan-out ─────────────────────────────────────────────

    public function test_creating_a_task_with_multiple_clients_logs_once_per_client(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');

        $this->actingAs($this->manager);
        app(TaskService::class)->create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'client_ids' => [$acme->id, $globex->id],
        ]);

        $rows = ActivityLog::where('module', 'Task')->where('action', 'Created')->get();

        $this->assertSame(2, $rows->count());
        $this->assertEqualsCanonicalizing([$acme->id, $globex->id], $rows->pluck('client_id')->all());
    }

    public function test_creating_an_internal_task_logs_once_with_no_client(): void
    {
        $this->actingAs($this->manager);
        app(TaskService::class)->create([
            'title' => 'Internal task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
        ]);

        $rows = ActivityLog::where('module', 'Task')->where('action', 'Created')->get();

        $this->assertSame(1, $rows->count());
        $this->assertNull($rows->first()->client_id);
    }

    public function test_updating_a_tasks_clients_logs_for_the_new_set(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');

        $this->actingAs($this->manager);
        $task = app(TaskService::class)->create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'client_ids' => [$acme->id],
        ]);
        ActivityLog::query()->delete(); // isolate the update's own logging

        app(TaskService::class)->update($task, [
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'client_ids' => [$globex->id],
        ]);

        $rows = ActivityLog::where('module', 'Task')->where('action', 'Updated')->get();

        $this->assertSame(1, $rows->count());
        $this->assertSame($globex->id, $rows->first()->client_id);
    }

    public function test_deleting_a_task_logs_for_every_client_it_had(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');

        $this->actingAs($this->manager);
        $task = app(TaskService::class)->create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'client_ids' => [$acme->id, $globex->id],
        ]);
        ActivityLog::query()->delete();

        app(TaskService::class)->delete($task);

        $rows = ActivityLog::where('module', 'Task')->where('action', 'Deleted')->get();
        $this->assertEqualsCanonicalizing([$acme->id, $globex->id], $rows->pluck('client_id')->all());
    }

    // ── Listing / rendering ──────────────────────────────────────────────

    public function test_the_task_list_column_joins_every_clients_name(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');
        $task   = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->manager->id, 'created_by' => $this->manager->id,
        ]);
        $task->clients()->sync([$acme->id, $globex->id]);

        $row = $this->actingAs($this->manager)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('tasks.index') . '?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('ACME, Globex', $row['client']);
    }

    public function test_filtering_the_list_by_client_finds_a_task_with_several(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');
        $task   = Task::create([
            'title' => 'Shared task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->manager->id, 'created_by' => $this->manager->id,
        ]);
        $task->clients()->sync([$acme->id, $globex->id]);

        // Filtering by either client finds the same task.
        foreach ([$acme, $globex] as $client) {
            $this->actingAs($this->manager)
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->getJson(route('tasks.index', ['client_id' => $client->id]) . '&draw=1&start=0&length=10')
                ->assertOk()
                ->assertJsonPath('recordsFiltered', 1)
                ->assertJsonPath('data.0.id', $task->id);
        }
    }

    public function test_the_task_page_lists_every_client(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');
        $task   = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->manager->id, 'created_by' => $this->manager->id,
        ]);
        $task->clients()->sync([$acme->id, $globex->id]);

        $this->actingAs($this->manager)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('ACME')
            ->assertSee('Globex')
            ->assertDontSee('Internal task');
    }

    public function test_the_task_page_shows_internal_task_when_no_clients(): void
    {
        $task = Task::create([
            'title' => 'Internal', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->manager->id, 'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Internal task');
    }

    public function test_the_edit_dialogs_json_exposes_every_client_id(): void
    {
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');
        $task   = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $this->manager->id, 'created_by' => $this->manager->id,
        ]);
        $task->clients()->sync([$acme->id, $globex->id]);

        $ids = $this->actingAs($this->manager)
            ->getJson(route('tasks.show', $task))
            ->assertOk()
            ->json('task.clients.*.id');

        $this->assertEqualsCanonicalizing([$acme->id, $globex->id], $ids);
    }
}
