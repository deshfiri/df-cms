<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Not every task is client work. Delegating something to a colleague, or any
 * internal job, should not require picking an unrelated client — which also
 * kept that client's task history honest.
 */
class InternalTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view tasks', 'manage tasks', 'manage all tasks']);

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'    => 'Write the onboarding doc',
            'priority' => 'Medium',
            'status'   => 'Pending',
            'type'     => 'Other',
        ], $overrides);
    }

    public function test_a_task_can_be_created_with_no_client(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload())
            ->assertOk();

        $task = Task::firstOrFail();

        $this->assertTrue($task->clients->isEmpty());
        $this->assertSame('Write the onboarding doc', $task->title);
    }

    public function test_a_junior_can_be_assigned_internal_work(): void
    {
        $manager = $this->manager();
        $junior  = User::factory()->create(['is_active' => true]);

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload(['assignee_ids' => [$junior->id]]))
            ->assertOk();

        $this->assertSame([$junior->id], Task::firstOrFail()->assignees->pluck('id')->all());
    }

    public function test_the_list_renders_a_clientless_task(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->postJson(route('tasks.store'), $this->payload())->assertOk();

        $row = $this->actingAs($manager)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('tasks.index') . '?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        // Rendered as a dash rather than blowing up on a null relation.
        $this->assertSame('-', $row['client']);
    }

    public function test_an_internal_task_still_moves_through_submit_and_review(): void
    {
        $manager = $this->manager();
        $junior  = User::factory()->create(['is_active' => true]);

        $task = app(TaskService::class)->create($this->payload(['assignee_ids' => [$junior->id]]) + [
            'client_ids' => [],
        ]);

        $this->actingAs($junior)->postJson(route('tasks.progress', $task), ['status' => 'In Progress'])->assertOk();
        $this->actingAs($junior)->postJson(route('tasks.submit', $task))->assertOk();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);

        // The activity log takes a nullable client id, so nothing here needs one.
        $this->actingAs($manager)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertOk();

        $this->assertSame('Completed', $task->fresh()->status);
    }

    private function client(string $name = 'ACME Ltd'): \App\Models\Client
    {
        $category = \App\Models\Category::firstOrCreate(['slug' => 'cat-' . uniqid()], ['name' => 'Cat', 'status' => true]);

        return \App\Models\Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => $name,
            'category_id' => $category->id,
        ]);
    }

    public function test_a_client_can_still_be_attached(): void
    {
        $manager = $this->manager();
        $client  = $this->client();

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload(['client_ids' => [$client->id]]))
            ->assertOk();

        $this->assertSame([$client->id], Task::firstOrFail()->clients->pluck('id')->all());
    }

    /** The headline feature: a task can be for more than one client at once. */
    public function test_a_task_can_be_attached_to_multiple_clients_at_once(): void
    {
        $manager = $this->manager();
        $acme    = $this->client('ACME Ltd');
        $globex  = $this->client('Globex Inc');

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload(['client_ids' => [$acme->id, $globex->id]]))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$acme->id, $globex->id],
            Task::firstOrFail()->clients->pluck('id')->all(),
        );
    }

    public function test_updating_a_tasks_clients_replaces_rather_than_adds(): void
    {
        $manager = $this->manager();
        $acme    = $this->client('ACME Ltd');
        $globex  = $this->client('Globex Inc');

        $task = app(TaskService::class)->create($this->payload() + ['client_ids' => [$acme->id]]);

        $this->actingAs($manager)
            ->putJson(route('tasks.update', $task), $this->payload(['client_ids' => [$globex->id]]))
            ->assertOk();

        $this->assertSame([$globex->id], $task->fresh()->clients->pluck('id')->all());
    }

    public function test_clearing_every_client_on_update_leaves_the_task_internal(): void
    {
        $manager = $this->manager();
        $acme    = $this->client();

        $task = app(TaskService::class)->create($this->payload() + ['client_ids' => [$acme->id]]);

        $this->actingAs($manager)
            ->putJson(route('tasks.update', $task), $this->payload(['client_ids' => []]))
            ->assertOk();

        $this->assertTrue($task->fresh()->clients->isEmpty());
    }

    public function test_an_unknown_client_is_still_rejected(): void
    {
        $this->actingAs($this->manager())
            ->postJson(route('tasks.store'), $this->payload(['client_ids' => [999999]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_ids.0');
    }

    public function test_one_unknown_client_among_valid_ones_rejects_the_whole_request(): void
    {
        $client = $this->client();

        $this->actingAs($this->manager())
            ->postJson(route('tasks.store'), $this->payload(['client_ids' => [$client->id, 999999]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_ids.1');

        $this->assertSame(0, Task::count());
    }
}
