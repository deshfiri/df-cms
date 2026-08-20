<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Super Admin can grant a permission to one person without inventing a role for
 * them. Direct grants sit on top of whatever their roles already allow; they
 * never take role-granted access away.
 */
class UserPermissionGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view clients', 'manage clients', 'view reports', 'export clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    private function staff(array $rolePermissions = []): User
    {
        $role = Role::firstOrCreate(['name' => 'Sales', 'guard_name' => 'web']);
        $role->syncPermissions($rolePermissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Sales');

        return $user->fresh();
    }

    public function test_a_super_admin_can_grant_a_permission_to_one_user(): void
    {
        $staff = $this->staff(['view clients']);

        $this->assertFalse($staff->can('view reports'));

        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), [
                'permissions' => ['view reports'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($staff->fresh()->can('view reports'));
        // Their role's grant is untouched.
        $this->assertTrue($staff->fresh()->can('view clients'));
    }

    public function test_a_direct_grant_can_be_taken_back(): void
    {
        $staff = $this->staff(['view clients']);
        $staff->givePermissionTo('view reports');

        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), ['permissions' => []])
            ->assertOk();

        $this->assertFalse($staff->fresh()->can('view reports'));
        $this->assertTrue($staff->fresh()->can('view clients'));
    }

    public function test_role_granted_access_survives_a_sync_that_omits_it(): void
    {
        $staff = $this->staff(['view clients', 'manage clients']);

        // The form only ever submits direct grants; role permissions are not in
        // the payload and must not be stripped.
        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), ['permissions' => ['export clients']])
            ->assertOk();

        $fresh = $staff->fresh();
        $this->assertTrue($fresh->can('view clients'));
        $this->assertTrue($fresh->can('manage clients'));
        $this->assertTrue($fresh->can('export clients'));
    }

    public function test_a_grant_that_a_role_also_gives_is_kept_when_resubmitted(): void
    {
        $staff = $this->staff(['view reports']);
        $staff->givePermissionTo('view reports');

        // The permission is both direct and role-derived. Submitting it back
        // must preserve the direct grant, so the user keeps the permission if
        // the role later loses it.
        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), ['permissions' => ['view reports']])
            ->assertOk();

        $this->assertTrue($staff->fresh()->getDirectPermissions()->contains('name', 'view reports'));

        Role::findByName('Sales')->syncPermissions([]);

        $this->assertTrue($staff->fresh()->can('view reports'));
    }

    public function test_the_endpoint_reports_where_each_grant_comes_from(): void
    {
        $staff = $this->staff(['view clients']);
        $staff->givePermissionTo('view reports');

        $body = $this->actingAs($this->superAdmin())
            ->getJson(route('permissions.user.show', $staff))
            ->assertOk()
            ->json();

        $this->assertSame(['view reports'], $body['direct']);
        $this->assertSame(['view clients'], $body['via_roles']);
        $this->assertSame(['Sales'], $body['roles']);
        $this->assertFalse($body['is_super_admin']);
    }

    public function test_a_super_admin_target_is_flagged(): void
    {
        $body = $this->actingAs($this->superAdmin())
            ->getJson(route('permissions.user.show', $this->superAdmin()))
            ->assertOk()
            ->json();

        $this->assertTrue($body['is_super_admin']);
    }

    public function test_the_change_is_recorded_in_the_activity_log(): void
    {
        $staff = $this->staff(['view clients']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), ['permissions' => ['view reports']])
            ->assertOk();

        $log = ActivityLog::where('module', 'Permissions')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString($staff->name, $log->action);
        $this->assertStringContainsString('view reports', (string) $log->new_value);
    }

    public function test_an_unchanged_sync_is_not_logged(): void
    {
        $staff = $this->staff(['view clients']);
        $staff->givePermissionTo('view reports');

        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), ['permissions' => ['view reports']])
            ->assertOk();

        $this->assertSame(0, ActivityLog::where('module', 'Permissions')->count());
    }

    public function test_an_unknown_permission_is_rejected(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->superAdmin())
            ->postJson(route('permissions.user.sync', $staff), [
                'permissions' => ['delete the database'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_nobody_but_a_super_admin_may_hand_out_permissions(): void
    {
        $staff  = $this->staff(['view clients']);
        $target = $this->staff();

        $this->actingAs($staff)
            ->getJson(route('permissions.user.show', $target))
            ->assertForbidden();

        $this->actingAs($staff)
            ->postJson(route('permissions.user.sync', $target), ['permissions' => ['manage clients']])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->can('manage clients'));
    }
}
