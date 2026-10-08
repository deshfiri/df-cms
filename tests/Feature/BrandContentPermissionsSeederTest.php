<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BrandContentPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BrandContentPermissionsSeeder: the production-safe, additive-only
 * provisioning mechanism for the Brand Content & Advertising permissions and
 * the Social Media Manager role. Every test here proves the critical rule —
 * add only, never remove or replace an existing production grant — the same
 * guarantee WhatsAppPermissionsSeeder already established for its own
 * permissions.
 */
class BrandContentPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'view raw-content-panel',
        'manage raw-content',
        'view designer-panel',
        'manage designer-content',
        'view smm-panel',
        'manage smm-collection',
        'manage published-content',
        'manage content-charges',
        'manage advertising-expenditure',
        'manage publishing-review',
        'view brand-checklist-overview',
    ];

    private function runSeeder(): void
    {
        (new BrandContentPermissionsSeeder)->run();
    }

    private function makeRole(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => 'web']);
    }

    // ── A. Missing permissions are created ──────────────────────────────────

    public function test_missing_brand_content_permissions_are_created(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $this->assertFalse(Permission::where('name', $permission)->exists());
        }

        $this->runSeeder();

        foreach (self::PERMISSIONS as $permission) {
            $this->assertTrue(
                Permission::where('name', $permission)->where('guard_name', 'web')->exists(),
                "Permission [{$permission}] was not created."
            );
        }
    }

    // ── B–C. Social Media Manager role created, with the correct permissions ─

    public function test_social_media_manager_role_is_created_with_the_correct_permissions(): void
    {
        $this->assertFalse(Role::where('name', 'Social Media Manager')->exists());

        $this->runSeeder();

        $role = Role::where('name', 'Social Media Manager')->where('guard_name', 'web')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('view clients'));
        $this->assertTrue($role->hasPermissionTo('view smm-panel'));
        $this->assertTrue($role->hasPermissionTo('manage smm-collection'));
        $this->assertTrue($role->hasPermissionTo('manage published-content'));
    }

    // ── D. Correct new permissions are added to the existing roles ─────────

    public function test_the_correct_new_permissions_are_added_to_each_existing_role(): void
    {
        $content = $this->makeRole('Content');
        $design = $this->makeRole('Design');
        $marketing = $this->makeRole('Marketing');
        $manager = $this->makeRole('Manager');

        $this->runSeeder();

        $content->refresh();
        $design->refresh();
        $marketing->refresh();
        $manager->refresh();

        $this->assertTrue($content->hasPermissionTo('view raw-content-panel'));
        $this->assertTrue($content->hasPermissionTo('manage raw-content'));

        $this->assertTrue($design->hasPermissionTo('view designer-panel'));
        $this->assertTrue($design->hasPermissionTo('manage designer-content'));

        $this->assertTrue($marketing->hasPermissionTo('manage content-charges'));
        $this->assertTrue($marketing->hasPermissionTo('manage advertising-expenditure'));
        $this->assertTrue($marketing->hasPermissionTo('manage publishing-review'));

        $this->assertTrue($manager->hasPermissionTo('view brand-checklist-overview'));
    }

    // ── E/F. Existing unrelated permissions survive — never syncPermissions ──

    public function test_existing_unrelated_permissions_on_those_roles_survive_provisioning(): void
    {
        $marketing = $this->makeRole('Marketing');
        $content = $this->makeRole('Content');
        Permission::create(['name' => 'an existing marketing permission', 'guard_name' => 'web']);
        Permission::create(['name' => 'an existing content permission', 'guard_name' => 'web']);
        $marketing->givePermissionTo('an existing marketing permission');
        $content->givePermissionTo('an existing content permission');

        $this->runSeeder();

        $marketing->refresh();
        $content->refresh();
        $this->assertTrue($marketing->hasPermissionTo('an existing marketing permission'), 'An existing grant must survive — this proves syncPermissions() was not used.');
        $this->assertTrue($content->hasPermissionTo('an existing content permission'));
        // And the new grant was still added alongside it.
        $this->assertTrue($marketing->hasPermissionTo('manage publishing-review'));
    }

    public function test_an_expected_missing_role_is_reported_and_never_silently_created(): void
    {
        // Content/Design/Manager exist; Marketing deliberately does not.
        $this->makeRole('Content');
        $this->makeRole('Design');
        $this->makeRole('Manager');

        $this->runSeeder();

        $this->assertFalse(Role::where('name', 'Marketing')->exists(), 'A missing expected role must never be silently created.');
        // The rest of provisioning still completed.
        $this->assertTrue(Role::where('name', 'Social Media Manager')->exists());
        $this->assertTrue(Permission::where('name', 'manage raw-content')->exists());
    }

    // ── G. Idempotency ───────────────────────────────────────────────────────

    public function test_running_the_seeder_twice_is_idempotent(): void
    {
        $this->makeRole('Content');
        $this->makeRole('Design');
        $this->makeRole('Marketing');
        $this->makeRole('Manager');

        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(1, Role::where('name', 'Social Media Manager')->count(), 'No duplicate role.');
        foreach (self::PERMISSIONS as $permission) {
            $this->assertSame(1, Permission::where('name', $permission)->count(), "Duplicate permission [{$permission}].");
        }

        $smm = Role::where('name', 'Social Media Manager')->first();
        foreach (['view clients', 'view smm-panel', 'manage smm-collection', 'manage published-content'] as $permission) {
            $this->assertTrue($smm->hasPermissionTo($permission), 'No permission loss after a second run.');
        }

        $marketing = Role::where('name', 'Marketing')->first();
        foreach (['manage content-charges', 'manage advertising-expenditure', 'manage publishing-review'] as $permission) {
            $this->assertTrue($marketing->hasPermissionTo($permission));
        }
    }

    // ── H. No User rows are ever created ────────────────────────────────────

    public function test_no_user_rows_are_created(): void
    {
        $before = User::count();

        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame($before, User::count(), 'This seeder must never create, update, or delete a User row.');
    }

    // ── I. The legacy "manage workflow" permission is never touched ─────────

    public function test_the_legacy_manage_workflow_permission_survives_provisioning(): void
    {
        Permission::create(['name' => 'manage workflow', 'guard_name' => 'web']);

        $this->runSeeder();

        $this->assertTrue(
            Permission::where('name', 'manage workflow')->exists(),
            'This seeder must never delete any permission, including the legacy duplicate DatabaseSeeder removes.'
        );
    }

    // ── J. A custom permission on an existing SMM role survives a re-run ────

    public function test_a_custom_permission_on_an_existing_smm_role_survives_reprovisioning(): void
    {
        $smm = $this->makeRole('Social Media Manager');
        Permission::create(['name' => 'a custom smm permission', 'guard_name' => 'web']);
        $smm->givePermissionTo('a custom smm permission');

        $this->runSeeder();
        $this->runSeeder();

        $smm->refresh();
        $this->assertTrue($smm->hasPermissionTo('a custom smm permission'), 'A hand-granted custom permission must survive provisioning and re-provisioning.');
        $this->assertTrue($smm->hasPermissionTo('manage smm-collection'), 'The normal provisioned permissions are still granted alongside it.');
    }

    // ── Never syncPermissions()/syncRoles() — static proof by source inspection ──

    public function test_the_seeder_source_never_calls_sync_permissions_or_sync_roles(): void
    {
        $source = file_get_contents(base_path('database/seeders/BrandContentPermissionsSeeder.php'));

        // Method-call patterns only — the docblock prose deliberately names
        // syncPermissions()/syncRoles() when explaining why DatabaseSeeder is
        // unsafe, so a bare substring check would false-positive on that
        // explanation itself.
        $this->assertStringNotContainsString('->syncPermissions(', $source);
        $this->assertStringNotContainsString('->syncRoles(', $source);
        $this->assertStringNotContainsString('Hash::make', $source);
        $this->assertStringNotContainsString('User::create', $source);
        $this->assertStringNotContainsString('App\\Models\\User', $source, 'This seeder must never reference the User model at all.');
        // Deletion is proved behaviorally instead of by a source-text check —
        // the docblock itself names DatabaseSeeder's `->delete(` cleanup when
        // explaining why this seeder deliberately does not reproduce it, so a
        // bare substring match would false-positive on that explanation. See
        // test_the_legacy_manage_workflow_permission_survives_provisioning().
    }

    public function test_the_seeder_is_not_wired_into_the_dangerous_database_seeder(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString('BrandContentPermissionsSeeder', $source, 'DatabaseSeeder must stay clearly separate from this production-safe seeder.');
    }
}
