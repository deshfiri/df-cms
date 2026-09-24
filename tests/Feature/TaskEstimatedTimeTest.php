<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Estimated completion time is entered in the task editor as a value plus a
 * unit — minutes, hours or days — but the server only ever stores and works
 * in hours (Task::$estimated_hours, decimal(6,2)); the unit picker is a pure
 * input convenience converted client-side, so this covers the server's half
 * of the contract: it accepts whatever hours figure a unit conversion
 * produces, round-trips it correctly through the timer, and rejects a
 * figure the column can't actually hold.
 */
class TaskEstimatedTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view tasks', 'manage tasks', 'manage all tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view tasks', 'manage tasks', 'manage all tasks'])->fresh();
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Estimate test', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
        ], $overrides);
    }

    public function test_an_estimate_given_in_days_round_trips_as_hours(): void
    {
        // "3 days" converted client-side to 72 hours.
        $this->actingAs($this->manager)->postJson(route('tasks.store'), $this->basePayload(['estimated_hours' => 72]))
            ->assertOk();

        $task = Task::firstOrFail();
        $this->assertSame('72.00', (string) $task->estimated_hours);

        $this->actingAs($this->manager)->getJson(route('tasks.show', $task))
            ->assertOk()
            ->assertJsonPath('timer.estimated_seconds', 72 * 3600);
    }

    public function test_an_estimate_given_in_minutes_round_trips_as_hours(): void
    {
        // "30 minutes" converted client-side to 0.5 hours.
        $this->actingAs($this->manager)->postJson(route('tasks.store'), $this->basePayload(['estimated_hours' => 0.5]))
            ->assertOk();

        $task = Task::firstOrFail();

        $this->actingAs($this->manager)->getJson(route('tasks.show', $task))
            ->assertOk()
            ->assertJsonPath('timer.estimated_seconds', 1800);
    }

    public function test_a_task_with_no_estimate_is_unaffected(): void
    {
        $this->actingAs($this->manager)->postJson(route('tasks.store'), $this->basePayload())
            ->assertOk();

        $task = Task::firstOrFail();
        $this->assertNull($task->estimated_hours);
    }

    public function test_an_estimate_beyond_what_the_column_can_hold_is_rejected_on_create(): void
    {
        // 999999 days converted client-side comfortably overflows decimal(6,2).
        $this->actingAs($this->manager)
            ->postJson(route('tasks.store'), $this->basePayload(['estimated_hours' => 999999 * 24]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('estimated_hours');

        $this->assertSame(0, Task::count());
    }

    public function test_an_estimate_beyond_what_the_column_can_hold_is_rejected_on_update(): void
    {
        $this->actingAs($this->manager);
        $task = Task::create($this->basePayload(['created_by' => $this->manager->id]));

        $this->actingAs($this->manager)->putJson(route('tasks.update', $task), $this->basePayload([
            'estimated_hours' => 999999 * 24,
        ]))->assertStatus(422)->assertJsonValidationErrors('estimated_hours');

        $this->assertNull($task->fresh()->estimated_hours);
    }

    public function test_the_editor_offers_minutes_hours_and_days(): void
    {
        $this->actingAs($this->manager)->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('id="taskEstValue"', false)
            ->assertSee('id="taskEstUnit"', false)
            ->assertSee('<option value="minutes">Minutes</option>', false)
            ->assertSee('<option value="hours" selected>Hours</option>', false)
            ->assertSee('<option value="days">Days</option>', false);
    }
}
