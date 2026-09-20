<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings → Document Types: the list of client document types staff and
 * clients file uploads under.
 *
 * Deliberately not 'manage documents': that is held by everyone who uploads a
 * client's papers, and renaming or retiring a type changes the list for the
 * whole company. Starts with Super Admin and Manager; grant it to any other
 * role in Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage document types', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage document types')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
