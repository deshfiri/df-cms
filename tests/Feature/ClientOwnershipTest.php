<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientOwnershipTransfer;
use App\Models\User;
use App\Notifications\ClientOwnershipBulkTransferred;
use App\Notifications\ClientOwnershipTransferred;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'manage clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete clients', 'guard_name' => 'web']);
    }

    private function makeClient(?int $assignedTo = null): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number'  => 'DF' . uniqid(),
            'client_name'  => 'Test Client',
            'brand_name'   => 'Test Brand',
            'category_id'  => $category->id,
            'assigned_to'  => $assignedTo,
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        $user->givePermissionTo('manage clients');

        return $user;
    }

    public function test_a_non_owner_cannot_edit_a_client_assigned_to_someone_else(): void
    {
        $owner = $this->makeUser('Sales');
        $otherSales = $this->makeUser('Sales');
        $client = $this->makeClient($owner->id);

        $this->assertFalse($otherSales->can('update', $client));
        $this->assertTrue($owner->can('update', $client));
    }

    public function test_anyone_with_permission_can_edit_an_unassigned_client(): void
    {
        $sales = $this->makeUser('Sales');
        $client = $this->makeClient(null);

        $this->assertTrue($sales->can('update', $client));
    }

    public function test_only_super_admin_bypasses_ownership_restriction(): void
    {
        $manager = $this->makeUser('Manager');
        $superAdmin = $this->makeUser('Super Admin');
        $owner = $this->makeUser('Sales');
        $client = $this->makeClient($owner->id);

        // Manager is scoped exactly like everyone else now — only Super Admin is unrestricted.
        $this->assertFalse($manager->can('update', $client));
        $this->assertTrue($superAdmin->can('update', $client));
    }

    public function test_update_status_route_is_blocked_for_a_non_owner(): void
    {
        $owner = $this->makeUser('Sales');
        $otherSales = $this->makeUser('Sales');
        $client = $this->makeClient($owner->id);

        $response = $this->actingAs($otherSales)->postJson(route('clients.status', $client), ['status' => 'Hold']);
        $response->assertForbidden();
        $this->assertSame('Running', $client->fresh()->client_status ?? 'Running');
    }

    public function test_transfer_creates_history_notifies_admin_and_new_owner(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Super Admin');
        $owner = $this->makeUser('Sales');
        $newOwner = $this->makeUser('Sales');
        $client = $this->makeClient($owner->id);

        $response = $this->actingAs($owner)->postJson(route('clients.transfer', $client), [
            'new_owner_id' => $newOwner->id,
            'note'         => 'Handing off while I am on leave',
        ]);

        $response->assertOk();
        $this->assertSame($newOwner->id, $client->fresh()->assigned_to);

        $this->assertDatabaseHas('client_ownership_transfers', [
            'client_id'         => $client->id,
            'previous_owner_id' => $owner->id,
            'new_owner_id'      => $newOwner->id,
            'transferred_by'    => $owner->id,
            'note'              => 'Handing off while I am on leave',
        ]);

        Notification::assertSentTo($admin, ClientOwnershipTransferred::class);
        Notification::assertSentTo($newOwner, ClientOwnershipTransferred::class);
    }

    public function test_transfer_is_blocked_for_someone_who_does_not_own_the_client(): void
    {
        $owner = $this->makeUser('Sales');
        $unrelatedSales = $this->makeUser('Sales');
        $newOwner = $this->makeUser('Sales');
        $client = $this->makeClient($owner->id);

        $response = $this->actingAs($unrelatedSales)->postJson(route('clients.transfer', $client), [
            'new_owner_id' => $newOwner->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($owner->id, $client->fresh()->assigned_to);
    }

    public function test_transfer_is_blocked_on_an_unassigned_client_for_non_privileged_users(): void
    {
        $sales = $this->makeUser('Sales');
        $newOwner = $this->makeUser('Sales');
        $client = $this->makeClient(null);

        // No owner to transfer from — "transfer" requires actually owning it.
        $response = $this->actingAs($sales)->postJson(route('clients.transfer', $client), [
            'new_owner_id' => $newOwner->id,
        ]);

        $response->assertForbidden();
    }

    public function test_self_transfer_is_rejected(): void
    {
        $owner = $this->makeUser('Sales');
        $client = $this->makeClient($owner->id);

        $response = $this->actingAs($owner)->postJson(route('clients.transfer', $client), [
            'new_owner_id' => $owner->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_bulk_assign_works_for_manager_and_is_blocked_for_sales(): void
    {
        $manager = $this->makeUser('Manager');
        $sales = $this->makeUser('Sales');
        $newOwner = $this->makeUser('Sales');
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        $blocked = $this->actingAs($sales)->postJson(route('clients.bulk-assign'), [
            'ids'          => [$clientA->id, $clientB->id],
            'new_owner_id' => $newOwner->id,
        ]);
        $blocked->assertForbidden();

        $allowed = $this->actingAs($manager)->postJson(route('clients.bulk-assign'), [
            'ids'          => [$clientA->id, $clientB->id],
            'new_owner_id' => $newOwner->id,
        ]);
        $allowed->assertOk();

        $this->assertSame($newOwner->id, $clientA->fresh()->assigned_to);
        $this->assertSame($newOwner->id, $clientB->fresh()->assigned_to);
        $this->assertSame(2, ClientOwnershipTransfer::count());
    }

    public function test_bulk_assign_sends_one_consolidated_notification_instead_of_one_per_client(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Super Admin');
        $manager = $this->makeUser('Manager');
        $newOwner = $this->makeUser('Sales');
        $clients = collect(range(1, 5))->map(fn () => $this->makeClient());

        $response = $this->actingAs($manager)->postJson(route('clients.bulk-assign'), [
            'ids'          => $clients->pluck('id')->all(),
            'new_owner_id' => $newOwner->id,
        ]);
        $response->assertOk();

        // One audit row per client, but a single batched notification per recipient.
        $this->assertSame(5, ClientOwnershipTransfer::count());
        Notification::assertSentTo($admin, ClientOwnershipBulkTransferred::class);
        Notification::assertSentTo($newOwner, ClientOwnershipBulkTransferred::class);
        Notification::assertSentTimes(ClientOwnershipBulkTransferred::class, 2);
        Notification::assertSentTimes(ClientOwnershipTransferred::class, 0);
    }

    public function test_transfer_to_an_inactive_user_is_rejected(): void
    {
        $owner = $this->makeUser('Sales');
        $inactiveTarget = $this->makeUser('Sales');
        $inactiveTarget->update(['is_active' => false]);
        $client = $this->makeClient($owner->id);

        $response = $this->actingAs($owner)->postJson(route('clients.transfer', $client), [
            'new_owner_id' => $inactiveTarget->id,
        ]);

        // Passes basic "exists:users,id" validation but fails the active-user
        // re-check inside the controller, which throws a 404 via findOrFail.
        $response->assertNotFound();
        $this->assertSame($owner->id, $client->fresh()->assigned_to);
    }

    private function dataTableContainsName(User $user, string $name): bool
    {
        $response = $this->actingAs($user)->getJson(route('clients.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();

        return collect($response->json('data'))->contains(fn ($row) => str_contains($row['client'], $name));
    }

    /**
     * The clients list/DataTable IS filtered by ownership: a non-Super-Admin
     * viewer sees clients assigned to them plus unassigned clients, but not
     * clients assigned to someone else. Super Admin always sees everything.
     */
    public function test_list_shows_own_assigned_and_unassigned_clients_but_not_others(): void
    {
        $viewer  = $this->makeUser('Sales');
        $ownerB  = $this->makeUser('Sales');

        $this->makeClient($viewer->id)->update(['client_name' => 'Client Mine']);
        $this->makeClient($ownerB->id)->update(['client_name' => 'Client Someone Else']);
        $this->makeClient(null)->update(['client_name' => 'Client Unassigned']);

        $this->assertTrue($this->dataTableContainsName($viewer, 'Client Mine'));
        $this->assertTrue($this->dataTableContainsName($viewer, 'Client Unassigned'));
        $this->assertFalse($this->dataTableContainsName($viewer, 'Client Someone Else'));
    }

    public function test_manager_list_is_also_scoped_by_ownership(): void
    {
        $manager = $this->makeUser('Manager');
        $owner   = $this->makeUser('Sales');

        $this->makeClient($owner->id)->update(['client_name' => 'Client Owned By Sales']);
        $this->makeClient(null)->update(['client_name' => 'Client Free']);

        $this->assertFalse($this->dataTableContainsName($manager, 'Client Owned By Sales'));
        $this->assertTrue($this->dataTableContainsName($manager, 'Client Free'));
    }

    public function test_super_admin_sees_every_client_in_the_list_regardless_of_assignment(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $owner      = $this->makeUser('Sales');

        $this->makeClient($owner->id)->update(['client_name' => 'Client Assigned']);
        $this->makeClient(null)->update(['client_name' => 'Client Unassigned']);

        $this->assertTrue($this->dataTableContainsName($superAdmin, 'Client Assigned'));
        $this->assertTrue($this->dataTableContainsName($superAdmin, 'Client Unassigned'));
    }
}
