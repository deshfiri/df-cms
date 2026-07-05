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

    public function test_a_non_privileged_users_edit_is_held_pending_and_does_not_apply(): void
    {
        Notification::fake();

        $client = $this->makeClient();
        $sales = $this->makeUser('Sales');
        auth()->login($sales);

        $this->expectException(ChangeRequiresApprovalException::class);

        try {
            $this->clientService->update($client, ['remarks' => 'Changed by Sales', 'client_name' => $client->client_name, 'brand_name' => $client->brand_name, 'category_id' => $client->category_id]);
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
            $this->clientService->update($client, ['remarks' => 'Changed by Sales', 'client_name' => $client->client_name, 'brand_name' => $client->brand_name, 'category_id' => $client->category_id]);
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
                $this->clientService->update($client, [
                    'remarks' => $remarks,
                    'client_name' => $client->client_name,
                    'brand_name' => $client->brand_name,
                    'category_id' => $client->category_id,
                ]);
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
            $this->clientService->update($client, [
                'remarks' => 'Awaiting approval',
                'client_name' => $client->client_name,
                'brand_name' => $client->brand_name,
                'category_id' => $client->category_id,
            ]);
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
            $this->clientService->update($client, [
                'remarks' => 'Should not apply',
                'client_name' => $client->client_name,
                'brand_name' => $client->brand_name,
                'category_id' => $client->category_id,
            ]);
        } catch (ChangeRequiresApprovalException) {
            // expected
        }

        $pending = PendingChange::first();

        $response = $this->actingAs($manager)->postJson(route('pending-changes.reject', $pending), ['note' => 'Not needed']);
        $response->assertOk();

        $this->assertSame('Original remarks', $client->fresh()->remarks);
        $this->assertSame(PendingChange::STATUS_REJECTED, $pending->fresh()->status);
    }

    public function test_a_non_approver_cannot_access_the_pending_changes_queue(): void
    {
        $sales = $this->makeUser('Sales');

        $response = $this->actingAs($sales)->get(route('pending-changes.index'));
        $response->assertForbidden();
    }
}
