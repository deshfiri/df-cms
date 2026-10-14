<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role = null): User
    {
        $user = User::factory()->create(['is_active' => true]);

        if ($role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /** Same headers the Users page sends through jQuery's $.ajax. */
    private function ajaxDelete(User $actor, User $target)
    {
        return $this->actingAs($actor)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json, text/javascript, */*; q=0.01'])
            ->delete('/users/' . $target->id);
    }

    public function test_super_admin_can_delete_another_user(): void
    {
        $admin = $this->user('Super Admin');
        $target = $this->user('Design');

        $this->ajaxDelete($admin, $target)->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $target->id, 'model_type' => User::class]);
        $this->assertDatabaseHas('activity_logs', ['module' => 'User', 'action' => 'Deleted', 'user_id' => $admin->id]);
    }

    public function test_the_list_offers_delete_on_other_rows_but_not_your_own(): void
    {
        $admin = $this->user('Super Admin');
        $target = $this->user('Design');

        $rows = collect($this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson('/users?draw=1&start=0&length=100')
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertStringContainsString('btn-delete', $rows[$target->id]['actions']);
        $this->assertStringContainsString('btn-edit', $rows[$admin->id]['actions']);
        $this->assertStringNotContainsString('btn-delete', $rows[$admin->id]['actions']);
    }

    public function test_you_cannot_delete_your_own_account(): void
    {
        $admin = $this->user('Super Admin');
        $this->user('Super Admin'); // a second one, so only the self-check can refuse

        $this->ajaxDelete($admin, $admin)->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_last_super_admin_cannot_be_deleted(): void
    {
        Permission::firstOrCreate(['name' => 'manage users', 'guard_name' => 'web']);
        $manager = $this->user('Manager');
        $manager->givePermissionTo('manage users');
        $onlySuperAdmin = $this->user('Super Admin');

        $this->ajaxDelete($manager, $onlySuperAdmin)->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $onlySuperAdmin->id]);
    }

    public function test_a_super_admin_can_be_deleted_while_another_remains(): void
    {
        $admin = $this->user('Super Admin');
        $other = $this->user('Super Admin');

        $this->ajaxDelete($admin, $other)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_users_without_the_permission_cannot_delete(): void
    {
        $sales = $this->user('Sales');
        $target = $this->user('Design');

        $this->ajaxDelete($sales, $target)->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_linked_records_follow_the_schema_rules(): void
    {
        $admin = $this->user('Super Admin');
        $target = $this->user('Sales');
        $keeper = $this->user('Sales');

        // Owned by the target: cascades away with them.
        $owned = DB::table('sales_targets')->insertGetId(['user_id' => $target->id, 'period' => '2026-10', 'target_amount' => 100, 'created_by' => $admin->id]);
        // Someone else's, merely authored by the target: survives, author cleared.
        $authored = DB::table('sales_targets')->insertGetId(['user_id' => $keeper->id, 'period' => '2026-10', 'target_amount' => 200, 'created_by' => $target->id]);

        $this->ajaxDelete($admin, $target)->assertOk();

        $this->assertDatabaseMissing('sales_targets', ['id' => $owned]);
        $this->assertDatabaseHas('sales_targets', ['id' => $authored, 'user_id' => $keeper->id, 'created_by' => null]);
    }

    public function test_editing_a_user_still_works(): void
    {
        $admin = $this->user('Super Admin');
        $target = $this->user('Design');

        $this->actingAs($admin)->putJson('/users/' . $target->id, [
            'name' => 'Renamed', 'email' => $target->email, 'roles' => ['Design'], 'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Renamed']);
    }
}
