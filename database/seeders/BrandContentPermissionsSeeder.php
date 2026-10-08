<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the Brand Content & Advertising permissions, and the Social Media
 * Manager role, to an installation that already exists.
 *
 * DatabaseSeeder also defines these, but it cannot be re-run on live data:
 * it calls syncPermissions() per role, which resets EVERY role (not just the
 * new ones) to exactly its hardcoded list and would silently discard any
 * grant an administrator has since made by hand — and it would create ten
 * development accounts (admin@dfcp.com, smm@dfcp.com, marketing@dfcp.com,
 * ...) with the password "password" if those emails don't already exist.
 * DatabaseSeeder must never be run against a database that already has real
 * users and real data. This seeder exists so the Brand Content & Advertising
 * role/permission setup can be provisioned without ever touching that path.
 *
 * This one only ever adds:
 *   - a permission is created only if it does not already exist
 *     (Permission::firstOrCreate)
 *   - the Social Media Manager role is created only if it does not already
 *     exist (Role::firstOrCreate) — every OTHER role here must already
 *     exist; if an expected one is missing, it is reported and skipped,
 *     never silently created (see run())
 *   - every grant uses givePermissionTo(), never syncPermissions() or
 *     syncRoles() — nothing a role already holds, whether seeded earlier or
 *     granted by hand since, is ever removed
 *   - no Permission or Role row is ever deleted (this deliberately does NOT
 *     reproduce DatabaseSeeder's `Permission::where('name', 'manage
 *     workflow')->delete()` cleanup — that stays out of scope here)
 *   - no User row is ever created, updated, or deleted, and no password is
 *     ever touched — this seeder does not reference the users table at all
 *
 * Existing live SMM staff are assigned the Social Media Manager role
 * afterwards, through the existing admin "edit user" screen (UserController/
 * UserService) — a normal, explicit, one-user-at-a-time admin action. This
 * seeder only provisions the role and its permissions, never a user's roles.
 *
 * Safe to run on a running production system, and safe to run twice:
 *
 *   php artisan db:seed --class="Database\Seeders\BrandContentPermissionsSeeder" --force
 */
class BrandContentPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * Every Brand Content & Advertising permission the application's own
     * authorization checks actually require — mirrors DatabaseSeeder's
     * "Brand Content & Advertising system" permission block exactly.
     *
     * @var array<int,string>
     */
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

    /**
     * The new Social Media Manager role's full permission set, exactly as
     * DatabaseSeeder defines it. 'view clients' is a pre-existing,
     * company-wide permission (not one of the 11 above) — created
     * defensively in run() too, so this seeder never assumes something else
     * already ran first.
     *
     * @var array<int,string>
     */
    private const SMM_ROLE_PERMISSIONS = [
        'view clients',
        'view smm-panel',
        'manage smm-collection',
        'manage published-content',
    ];

    /**
     * Only the NEW permissions this feature adds to each pre-existing role —
     * never that role's full set, so anything else the role already holds is
     * left completely alone. Mirrors DatabaseSeeder's own role definitions
     * exactly (the Content/Design/Marketing/Manager entries).
     *
     * @var array<string,array<int,string>>
     */
    private const EXISTING_ROLE_GRANTS = [
        'Content' => ['view raw-content-panel', 'manage raw-content'],
        'Design' => ['view designer-panel', 'manage designer-content'],
        'Marketing' => ['manage content-charges', 'manage advertising-expenditure', 'manage publishing-review'],
        'Manager' => ['view brand-checklist-overview'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => self::GUARD]);
        }
        // SMM's own 'view clients' grant, defensively — see SMM_ROLE_PERMISSIONS's docblock.
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => self::GUARD]);

        // The one genuinely new role. firstOrCreate never touches an
        // already-existing "Social Media Manager" row or its existing grants.
        $smm = Role::firstOrCreate(['name' => 'Social Media Manager', 'guard_name' => self::GUARD]);
        // givePermissionTo, never syncPermissions — anything this role
        // already holds (e.g. a custom grant added by hand) must survive.
        $smm->givePermissionTo(self::SMM_ROLE_PERMISSIONS);

        foreach (self::EXISTING_ROLE_GRANTS as $roleName => $grants) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                // An expected pre-existing role is missing. Creating a
                // brand-new role under that name here would risk building an
                // incorrect production role topology, so this is reported
                // and skipped rather than silently created — see this
                // class's own docblock.
                $this->command?->error("Expected existing role [{$roleName}] was not found — skipped. Verify the role topology, then re-run this seeder.");

                continue;
            }

            // givePermissionTo, never syncPermissions — this role's existing
            // permission set is never replaced, only added to.
            $role->givePermissionTo($grants);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Brand Content & Advertising permissions provisioned. Existing grants were left untouched.');
    }
}
