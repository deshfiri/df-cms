<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The numbers on the filter pills.
 *
 * They were rendered once with the page and then went stale — a task finished by
 * somebody else, or by you in the same session, left them lying. Now every table
 * response carries them, counted from the same query the rows came from, with
 * one rule: a pill's own filter is not applied to its count, or picking one
 * status would show zero under every other.
 */
class FilterCountsTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view tasks', 'manage tasks', 'view clients', 'manage clients', 'view payments', 'manage payments'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo($permissions)->fresh();
    }

    private function client(string $name = 'ACME Ltd', string $status = 'Running'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number'   => 'DF' . uniqid(),
            'client_name'   => $name,
            'brand_name'    => 'ACME',
            'category_id'   => $category->id,
            'client_status' => $status,
        ]);
    }

    private function task(User $assignee, string $status, array $extra = []): Task
    {
        $clientIds = $extra['client_ids'] ?? [];
        unset($extra['client_ids']);

        $task = Task::create($extra + [
            'title' => 'Task ' . uniqid(), 'priority' => 'Medium', 'status' => $status, 'type' => 'Other',
            'assigned_to' => $assignee->id, 'created_by' => $assignee->id,
        ]);

        if ($clientIds) {
            $task->clients()->sync($clientIds);
        }

        return $task;
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function test_task_counts_come_back_with_the_rows(): void
    {
        $me = $this->user('view tasks', 'manage tasks');
        $this->task($me, 'Pending');
        $this->task($me, 'Pending');
        $this->task($me, 'Completed');
        $this->task($me, 'In Progress', ['due_date' => today()->subWeek()]);

        $this->actingAs($me)
            ->getJson(route('tasks.index'), self::AJAX)
            ->assertOk()
            ->assertJsonPath('counts.total', 4)
            ->assertJsonPath('counts.status.Pending', 2)
            ->assertJsonPath('counts.status.Completed', 1)
            ->assertJsonPath('counts.overdue', 1);
    }

    /** Picking a status must not zero the other pills — you could never leave. */
    public function test_choosing_one_status_leaves_the_other_counts_intact(): void
    {
        $me = $this->user('view tasks', 'manage tasks');
        $this->task($me, 'Pending');
        $this->task($me, 'Completed');

        $response = $this->actingAs($me)
            ->getJson(route('tasks.index', ['status' => 'Completed']), self::AJAX)
            ->assertOk()
            ->assertJsonPath('counts.status.Pending', 1)
            ->assertJsonPath('counts.status.Completed', 1);

        // The rows themselves are filtered, even though the counts are not.
        $this->assertSame(1, $response->json('recordsFiltered'));
    }

    /** Other filters do narrow them: the counts describe what you are looking at. */
    public function test_the_counts_follow_every_other_filter(): void
    {
        $me    = $this->user('view tasks', 'manage tasks');
        $acme  = $this->client('ACME');
        $other = $this->client('Beta');

        $this->task($me, 'Pending', ['client_ids' => [$acme->id]]);
        $this->task($me, 'Pending', ['client_ids' => [$other->id]]);
        $this->task($me, 'Completed', ['client_ids' => [$other->id]]);

        $this->actingAs($me)
            ->getJson(route('tasks.index', ['client_id' => $acme->id]), self::AJAX)
            ->assertOk()
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('counts.status.Pending', 1)
            ->assertJsonMissingPath('counts.status.Completed');
    }

    public function test_task_counts_never_include_work_you_cannot_see(): void
    {
        $me        = $this->user('view tasks');
        $colleague = $this->user('view tasks');
        $this->task($me, 'Pending');
        $this->task($colleague, 'Pending');

        $this->actingAs($me)
            ->getJson(route('tasks.index'), self::AJAX)
            ->assertJsonPath('counts.total', 1);
    }

    public function test_work_handed_back_to_me_is_counted_for_the_review_pill(): void
    {
        $me     = $this->user('view tasks', 'manage tasks');
        $junior = $this->user('view tasks');
        Task::create([
            'title' => 'Banner', 'priority' => 'Medium', 'status' => Task::STATUS_SUBMITTED, 'type' => 'Other',
            'assigned_to' => $junior->id, 'created_by' => $me->id,
        ]);

        $this->actingAs($me)
            ->getJson(route('tasks.index'), self::AJAX)
            ->assertJsonPath('counts.review', 1);
    }

    // ── Clients ──────────────────────────────────────────────────────────

    public function test_client_counts_come_back_with_the_rows(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = tap($this->user('view clients', 'manage clients'))->assignRole('Super Admin');

        $this->client('One', 'Running');
        $this->client('Two', 'Running');
        $this->client('Three', 'Hold');

        $this->actingAs($admin->fresh())
            ->getJson(route('clients.index', ['status' => 'Hold']), self::AJAX)
            ->assertOk()
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('counts.status.Running', 2)
            ->assertJsonPath('counts.status.Hold', 1)
            ->assertJsonPath('recordsFiltered', 1);
    }

    // ── Payments ─────────────────────────────────────────────────────────

    public function test_payment_counts_come_back_with_the_rows(): void
    {
        $accounts = $this->user('view payments', 'manage payments');
        $client   = $this->client();

        Payment::create(['client_id' => $client->id, 'status' => 'Paid', 'amount' => 100]);
        Payment::create(['client_id' => $client->id, 'status' => 'Paid', 'amount' => 50]);
        Payment::create(['client_id' => $client->id, 'status' => 'Unpaid', 'amount' => 25]);

        $this->actingAs($accounts)
            ->getJson(route('payments.index', ['status' => 'Unpaid']), self::AJAX)
            ->assertOk()
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('counts.status.Paid', 2)
            ->assertJsonPath('counts.status.Unpaid', 1)
            ->assertJsonPath('recordsFiltered', 1);
    }
}
