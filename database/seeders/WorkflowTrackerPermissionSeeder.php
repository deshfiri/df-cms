<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the read-only workflow tracker seat to an installation that already has
 * its roles set up.
 *
 * Additive on purpose: it grants the new permission and touches nothing else,
 * so running it can never undo grants an admin has made by hand.
 *
 *     php artisan db:seed --class=WorkflowTrackerPermissionSeeder
 */
class WorkflowTrackerPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'view workflows', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo($permission);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
