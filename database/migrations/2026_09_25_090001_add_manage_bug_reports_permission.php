<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bug Reports: who reviews and resolves what staff report about the system.
 *
 * Filing one is open to everyone — reporting an issue is part of having a
 * login, the same reasoning 'manage requests' already uses for Requests.
 * Starts with Super Admin and Manager; grant it to any other role in Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage bug reports', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage bug reports')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
