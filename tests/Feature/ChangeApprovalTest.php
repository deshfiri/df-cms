<?php

namespace Tests\Feature;

use App\Exceptions\ChangeRequiresApprovalException;
use App\Models\Category;
use App\Models\Client;
use App\Models\PendingChange;
use App\Models\User;
use App\Notifications\ChangeAwaitingApproval;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Change approval is deliberately narrow: it covers the client fields where a
 * quiet change matters (owner, status, category), plus payments and user
 * accounts. Ordinary detail edits — and all task, meeting and category work —
 * apply immediately, because gating them stopped people doing their jobs.
 */
class ChangeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private ClientService $clientService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientService = app(ClientService::class);

        foreach (['manage clients', 'manage payments', 'manage tasks', 'manage categories', 'manage users'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function makeClient(): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Test Client',
            'brand_name'  => 'Test Brand',
            'remarks'     => 'Original remarks',
            'category_id' => $category->id,
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    /**
     * An edit that touches a watched field (client_status) and so requires
     * approval, carrying an ordinary field along with it.
     */
    private function watchedEdit(Client $client, string $remarks): array
    {
        return [
            'remarks'       => $remarks,
            'client_status' => 'Warning',
            'client_name'   => $client->client_name,
            'brand_name'    => $client->brand_name,
            'category_id'   => $client->category_id,
        ];
    }

    public function test_a_non_privileged_users_edit_is_held_pending_and_does_not_apply(): void
    {
        Notification::fake();

        $client = $this->makeClient();
        $sales = $this->makeUser('Sales');
        auth()->login($sales);

        $this->expectException(ChangeRequiresApprovalException::class);

        try {
            $this->clientService->update($client, $this->watchedEdit($client, 'Changed by Sales'));
        } finally {
            $this->assertSame('Original remarks', $client->fresh()->remarks);
            $this->assertDatabaseHas('pending_changes', [
                'model_type'   => Client::class,
                'model_id'     => $client->id,
                'requested_by' => $sales->id,
                'status'       => PendingChange::STATUS_PENDING,
            ]);
        }
    }

    public function test_approvers_are_notified_but_the_requester_is_not(): void
    {
        Notification::fake();

        $client = $this->makeClient();
        $sales = $this->makeUser('Sales');
        $manager = $this->makeUser('Manager');
        auth()->login($sales);

        try {
            $this->clientService->update($client, $this->watchedEdit($client, 'Changed by Sales'));
        } catch (ChangeRequiresApprovalException) {
            // expected
        }

        Notification::assertSentTo($manager, ChangeAwaitingApproval::class);
        Notification::assertNotSentTo($sales, ChangeAwaitingApproval::class);
    }

    public function test_a_manager_edit_applies_immediately_with_no_pending_row(): void
    {
        $client = $this->makeClient();
        $manager = $this->makeUser('Manager');

        auth()->login($manager);
        $updated = $this->clientService->update($client, [
            'remarks' => 'Changed by Manager',
            'client_name' => $client->client_name,
            'brand_name' => $client->brand_name,
            'category_id' => $client->category_id,
        ]);

        $this->assertSame('Changed by Manager', $updated->remarks);
        $this->assertDatabaseCount('pending_changes', 0);
    }

    public function test_a_second_edit_before_review_amends_the_existing_pending_row_instead_of_duplicating(): void
    {
        $client = $this->makeClient();
        $sales = $this->makeUser('Sales');
        auth()->login($sales);

        foreach (['First edit', 'Second edit'] as $remarks) {
            try {
                $this->clientService->update($client, $this->watchedEdit($client, $remarks));
            } catch (ChangeRequiresApprovalException) {
                // expected
            }
        }

        $this->assertDatabaseCount('pending_changes', 1);
        $pending = PendingChange::first();
        $this->assertSame('Second edit', $pending->new_values['remarks']);
    }

    public function test_approving_a_pending_change_applies_it_and_marks_it_reviewed(): void
    {
        $client = $this->makeClient();
        $sales = $this->makeUser('Sales');
        $manager = $this->makeUser('Manager');
        auth()->login($sales);

        try {
            $this->clientService->update($client, $this->watchedEdit($client, 'Awaiting approval'));
        } catch (ChangeRequiresApprovalException) {
            // expected
        }

        $pending = PendingChange::first();

        $response = $this->actingAs($manager)->postJson(route('pending-changes.approve', $pending));
        $response->assertOk();

        $this->assertSame('Awaiting approval', $client->fresh()->remarks);
        $this->assertSame(PendingChange::STATUS_APPROVED, $pending->fresh()->status);
        $this->assertSame($manager->id, $pending->fresh()->reviewed_by);
    }

    public function test_rejecting_a_pending_change_leaves_the_record_untouched(): void
    {
        $client = $this->makeClient();
        $sales = $this->makeUser('Sales');
        $manager = $this->makeUser('Manager');
        auth()->login($sales);

        try {
            $this->clientService->update($client, $this->watchedEdit($client, 'Should not apply'));
        } catch (ChangeRequiresApprovalException) {
            // expected
        }

        $pending = PendingChange::first();

        $response = $this->actingAs($manager)->postJson(route('pending-changes.reject', $pending), ['note' => 'Not needed']);
        $response->assertOk();

        $this->assertSame('Original remarks', $client->fresh()->remarks);
        $this->assertSame(PendingChange::STATUS_REJECTED, $pending->fresh()->status);
    }

    // ── What is deliberately NOT gated ───────────────────────────────────

    public function test_an_ordinary_detail_edit_applies_immediately(): void
    {
        $client = $this->makeClient();
        auth()->login($this->makeUser('Sales'));

        // Correcting a remark or a phone number is not a four-eyes decision.
        $updated = $this->clientService->update($client, [
            'remarks'     => 'Called, will follow up Tuesday',
            'client_name' => $client->client_name,
            'brand_name'  => $client->brand_name,
        ]);

        $this->assertSame('Called, will follow up Tuesday', $updated->remarks);
        $this->assertDatabaseCount('pending_changes', 0);
    }

    public function test_resubmitting_a_watched_field_unchanged_is_not_an_approval(): void
    {
        $client = $this->makeClient();
        auth()->login($this->makeUser('Sales'));

        // The edit form posts every field, so a watched field present but
        // unchanged must not queue a change on its own.
        $updated = $this->clientService->update($client, [
            'remarks'     => 'Just a note',
            'category_id' => $client->category_id,
            'client_name' => $client->client_name,
            'brand_name'  => $client->brand_name,
        ]);

        $this->assertSame('Just a note', $updated->remarks);
        $this->assertDatabaseCount('pending_changes', 0);
    }

    public function test_changing_the_owner_still_needs_approval(): void
    {
        $client = $this->makeClient();
        $newOwner = $this->makeUser('Sales');
        auth()->login($this->makeUser('Sales'));

        $this->expectException(ChangeRequiresApprovalException::class);

        try {
            $this->clientService->update($client, [
                'assigned_to' => $newOwner->id,
                'client_name' => $client->client_name,
                'brand_name'  => $client->brand_name,
            ]);
        } finally {
            $this->assertNull($client->fresh()->assigned_to);
            $this->assertDatabaseCount('pending_changes', 1);
        }
    }

    public function test_a_task_edit_is_not_gated(): void
    {
        $client = $this->makeClient();
        $worker = $this->makeUser('Sales');
        auth()->login($worker);

        $task = \App\Models\Task::create([
            'title'       => 'Prepare the deck',
            'client_id'   => $client->id,
            'assigned_to' => $worker->id,
            'created_by'  => $worker->id,
            'status'      => 'In Progress',
            'priority'    => 'Medium',
        ]);

        // Marking your own work complete is the single most routine action in
        // the app; it must never wait on a manager.
        $updated = app(\App\Services\TaskService::class)->update($task, ['status' => 'Completed']);

        $this->assertSame('Completed', $updated->status);
        $this->assertDatabaseCount('pending_changes', 0);
    }

    public function test_a_category_edit_is_not_gated(): void
    {
        $category = Category::create(['name' => 'Retail', 'slug' => 'retail-' . uniqid(), 'status' => true]);
        auth()->login($this->makeUser('Sales'));

        $updated = app(\App\Services\CategoryService::class)->update($category, ['name' => 'Retail & Wholesale']);

        $this->assertSame('Retail & Wholesale', $updated->name);
        $this->assertDatabaseCount('pending_changes', 0);
    }

    public function test_a_non_approver_cannot_access_the_pending_changes_queue(): void
    {
        $sales = $this->makeUser('Sales');

        $response = $this->actingAs($sales)->get(route('pending-changes.index'));
        $response->assertForbidden();
    }
}
