<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The company dashboard becomes a permission of its own.
 *
 * It reports on clients, money and everyone's output — a management view, not
 * something every member of staff needs on the way to their own work. Without
 * it the menu item is gone and /dashboard lands on My Work instead.
 *
 * Starts with Super Admin and Manager; grant it to anyone else in Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'view dashboard', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'view dashboard')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
