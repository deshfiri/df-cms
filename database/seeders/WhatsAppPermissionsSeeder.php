<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the WhatsApp permissions to an installation that already exists.
 *
 * DatabaseSeeder also defines these, but it cannot be re-run on live data: it
 * calls syncPermissions() per role, which resets each role to exactly the seeded
 * list and would silently discard any grant an administrator has since made by
 * hand.
 *
 * This one only ever adds. Existing permissions and role grants are left alone,
 * so it is safe to run on a running system and safe to run twice.
 */
class WhatsAppPermissionsSeeder extends Seeder
{
    /** @var array<int,string> */
    private const PERMISSIONS = [
        'view whatsapp',
        'reply whatsapp',
        'assign whatsapp',
        'view all whatsapp',
        'manage whatsapp numbers',
        'manage whatsapp templates',
        'manage whatsapp settings',
    ];

    /**
     * Which roles get what.
     *
     * Mirrors DatabaseSeeder: a Manager runs the inbox but not the Meta
     * credentials, and Support/Marketing answer only threads handed to them —
     * no 'view all whatsapp', so an unassigned conversation stays invisible.
     *
     * @var array<string,array<int,string>>
     */
    private const ROLE_GRANTS = [
        'Manager' => [
            'view whatsapp', 'reply whatsapp', 'assign whatsapp', 'view all whatsapp',
            'manage whatsapp numbers', 'manage whatsapp templates',
        ],
        'Marketing' => ['view whatsapp', 'reply whatsapp'],
        'Support'   => ['view whatsapp', 'reply whatsapp'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Super Admin bypasses everything through Gate::before, but holding the
        // permissions explicitly keeps the Roles screen honest about what the
        // role can do.
        if ($superAdmin = Role::where('name', 'Super Admin')->first()) {
            $superAdmin->givePermissionTo(self::PERMISSIONS);
        }

        foreach (self::ROLE_GRANTS as $roleName => $grants) {
            if ($role = Role::where('name', $roleName)->first()) {
                // givePermissionTo, never syncPermissions — anything the role
                // already holds must survive.
                $role->givePermissionTo($grants);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('WhatsApp permissions added. Existing grants were left untouched.');
    }
}
