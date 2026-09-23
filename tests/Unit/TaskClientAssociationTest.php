<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The client_task pivot itself, at the model/relation level — separate from
 * the HTTP-level create/update/validation behavior covered in
 * Tests\Feature\InternalTaskTest.
 */
class TaskClientAssociationTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'ACME Ltd'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => $name, 'brand_name' => $name,
            'category_id' => $category->id,
        ]);
    }

    private function task(): Task
    {
        $user = User::factory()->create(['is_active' => true]);

        return Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assigned_to' => $user->id, 'created_by' => $user->id,
        ]);
    }

    public function test_a_task_can_be_linked_to_several_clients(): void
    {
        $task  = $this->task();
        $acme  = $this->client('ACME');
        $globex = $this->client('Globex');

        $task->clients()->sync([$acme->id, $globex->id]);

        $this->assertEqualsCanonicalizing([$acme->id, $globex->id], $task->fresh()->clients->pluck('id')->all());
    }

    public function test_a_client_can_be_linked_to_several_tasks(): void
    {
        $client = $this->client();
        $taskA  = $this->task();
        $taskB  = $this->task();

        $taskA->clients()->sync([$client->id]);
        $taskB->clients()->sync([$client->id]);

        $this->assertEqualsCanonicalizing([$taskA->id, $taskB->id], $client->fresh()->tasks->pluck('id')->all());
    }

    /**
     * Before this feature, deleting a client cascade-deleted every task
     * pointed at it. With a task now able to have several clients, that
     * would wrongly take out a task over just one of them — deleting a
     * client should only remove that one association.
     */
    public function test_deleting_a_client_detaches_it_without_deleting_the_task(): void
    {
        $task   = $this->task();
        $acme   = $this->client('ACME');
        $globex = $this->client('Globex');
        $task->clients()->sync([$acme->id, $globex->id]);

        $acme->delete(); // Client uses SoftDeletes — this is a soft delete.

        $this->assertNotNull(Task::find($task->id));
        $this->assertSame([$globex->id], $task->fresh()->clients->pluck('id')->all());
    }

    /** A client's soft delete does not remove the pivot row either — only forceDelete does. */
    public function test_a_clients_soft_delete_does_not_touch_the_pivot(): void
    {
        $task  = $this->task();
        $acme  = $this->client();
        $task->clients()->sync([$acme->id]);

        $acme->delete();

        $this->assertSame(1, DB::table('client_task')->where('task_id', $task->id)->count());
    }

    public function test_force_deleting_a_client_removes_the_pivot_row_via_cascade(): void
    {
        $task = $this->task();
        $acme = $this->client();
        $task->clients()->sync([$acme->id]);

        $acme->forceDelete();

        $this->assertSame(0, DB::table('client_task')->where('task_id', $task->id)->count());
        $this->assertNotNull(Task::find($task->id));
    }

    /** Task also uses SoftDeletes — a soft delete is an UPDATE, so the pivot survives it. */
    public function test_soft_deleting_a_task_keeps_its_client_associations(): void
    {
        $task = $this->task();
        $acme = $this->client();
        $task->clients()->sync([$acme->id]);

        $task->delete();

        $this->assertTrue($task->fresh()->trashed());
        $this->assertSame(1, DB::table('client_task')->where('task_id', $task->id)->count());
    }

    public function test_force_deleting_a_task_removes_its_pivot_rows(): void
    {
        $task = $this->task();
        $acme = $this->client();
        $task->clients()->sync([$acme->id]);

        $task->forceDelete();

        $this->assertSame(0, DB::table('client_task')->where('task_id', $task->id)->count());
    }
}
