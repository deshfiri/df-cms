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

        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view tasks', 'manage tasks']);

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

        $this->assertNull($task->client_id);
        $this->assertSame('Write the onboarding doc', $task->title);
    }

    public function test_a_junior_can_be_assigned_internal_work(): void
    {
        $manager = $this->manager();
        $junior  = User::factory()->create(['is_active' => true]);

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload(['assigned_to' => $junior->id]))
            ->assertOk();

        $this->assertSame($junior->id, Task::firstOrFail()->assigned_to);
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

        $task = app(TaskService::class)->create($this->payload(['assigned_to' => $junior->id]) + [
            'client_id' => null,
        ]);

        $this->actingAs($junior)->postJson(route('tasks.submit', $task))->assertOk();
        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);

        // The activity log takes a nullable client id, so nothing here needs one.
        $this->actingAs($manager)
            ->postJson(route('tasks.review', $task), ['accept' => true])
            ->assertOk();

        $this->assertSame('Completed', $task->fresh()->status);
    }

    public function test_a_client_can_still_be_attached(): void
    {
        $manager = $this->manager();

        $category = \App\Models\Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = \App\Models\Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), $this->payload(['client_id' => $client->id]))
            ->assertOk();

        $this->assertSame($client->id, Task::firstOrFail()->client_id);
    }

    public function test_an_unknown_client_is_still_rejected(): void
    {
        $this->actingAs($this->manager())
            ->postJson(route('tasks.store'), $this->payload(['client_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');
    }
}
