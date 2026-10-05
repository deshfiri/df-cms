<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Manager Oversight (route `manager.oversight`, permission
 * `view brand-checklist-overview`) existed and worked, but had no sidebar
 * link — reuses the existing Management section, existing `@can` convention
 * and existing active-state convention; no new route, permission, section
 * or panel.
 */
class ManagerOversightNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'view brand-checklist-overview', 'view raw-content-panel', 'view designer-panel',
            'view smm-panel', 'view ads', 'manage ads', 'view dashboard',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function user(string $role, array $permissions = []): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = tap(User::factory()->create(['is_active' => true]))->assignRole($role)->fresh();

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user->fresh();
    }

    public function test_a_manager_sees_the_manager_oversight_link_in_the_sidebar(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);

        $response = $this->actingAs($manager)->get(route('my-work'));

        $response->assertOk();
        $response->assertSee('Manager Oversight');
        $response->assertSee(route('manager.oversight'), false);
    }

    public function test_a_super_admin_sees_it_without_the_explicit_permission(): void
    {
        // Gate::before in AppServiceProvider grants Super Admin unrestricted
        // access — the link must honour the same bypass, not just the raw
        // permission row.
        $admin = $this->user('Super Admin');

        $response = $this->actingAs($admin)->get(route('my-work'));

        $response->assertOk();
        $response->assertSee('Manager Oversight');
    }

    public function test_content_does_not_see_it(): void
    {
        $content = $this->user('Content', ['view raw-content-panel']);

        $response = $this->actingAs($content)->get(route('my-work'));

        $response->assertOk();
        $response->assertDontSee('Manager Oversight');
    }

    public function test_design_does_not_see_it(): void
    {
        $designer = $this->user('Design', ['view designer-panel']);

        $response = $this->actingAs($designer)->get(route('my-work'));

        $response->assertOk();
        $response->assertDontSee('Manager Oversight');
    }

    public function test_smm_does_not_see_it(): void
    {
        $smm = $this->user('Social Media Manager', ['view smm-panel']);

        $response = $this->actingAs($smm)->get(route('my-work'));

        $response->assertOk();
        $response->assertDontSee('Manager Oversight');
    }

    public function test_marketing_does_not_see_it_without_the_permission_but_does_once_granted_it(): void
    {
        $marketing = $this->user('Marketing', ['view ads', 'manage ads']);

        $this->actingAs($marketing)->get(route('my-work'))->assertDontSee('Manager Oversight');

        // The gate is a plain, reusable permission check, not hardcoded
        // against specific roles — granting it independently must work.
        $marketing->givePermissionTo('view brand-checklist-overview');

        $this->actingAs($marketing->fresh())->get(route('my-work'))->assertSee('Manager Oversight');
    }

    public function test_the_link_points_at_the_existing_manager_oversight_route(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);

        $response = $this->actingAs($manager)->get(route('my-work'));

        $response->assertSee('href="' . route('manager.oversight') . '"', false);
    }

    public function test_the_active_state_applies_on_the_manager_oversight_page(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);

        $response = $this->actingAs($manager)->get(route('manager.oversight'));

        $response->assertOk();
        // Same convention every other sidebar link uses: "sb-link active" on
        // the current page, not just "sb-link".
        $response->assertSee('sb-link active" title="Manager Oversight"', false);
    }

    public function test_the_active_state_does_not_apply_on_other_pages(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);

        $response = $this->actingAs($manager)->get(route('my-work'));

        $response->assertDontSee('sb-link active" title="Manager Oversight"', false);
    }
}
