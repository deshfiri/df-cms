<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Admin group in the sidebar. These controllers register their guards with
 * $this->middleware() in the constructor, which is the legacy mechanism — these
 * tests exist to prove it is still actually applied, rather than trusting that
 * the code reads as if it is.
 */
class AdminAreaAuthorizationTest extends TestCase
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

    public function test_settings_are_super_admin_only(): void
    {
        $this->actingAs($this->user())->get(route('settings.index'))->assertForbidden();
        $this->actingAs($this->user('Manager'))->get(route('settings.index'))->assertForbidden();
    }

    public function test_roles_and_permissions_are_super_admin_only(): void
    {
        $this->actingAs($this->user())->get(route('roles.index'))->assertForbidden();
        $this->actingAs($this->user('Manager'))->get(route('roles.index'))->assertForbidden();
    }

    public function test_pending_changes_are_restricted_to_super_admin_and_manager(): void
    {
        $this->actingAs($this->user())->get(route('pending-changes.index'))->assertForbidden();
        $this->actingAs($this->user('Sales'))->get(route('pending-changes.index'))->assertForbidden();
    }

    public function test_a_manager_can_reach_pending_changes(): void
    {
        $this->actingAs($this->user('Manager'))
            ->get(route('pending-changes.index'))
            ->assertOk();
    }

    public function test_user_management_requires_the_permission(): void
    {
        $this->actingAs($this->user())->get(route('users.index'))->assertForbidden();
    }

    public function test_category_management_requires_the_permission(): void
    {
        $this->actingAs($this->user())->get(route('categories.index'))->assertForbidden();
    }
}
