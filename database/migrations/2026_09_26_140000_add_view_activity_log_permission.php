<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A company-wide activity log: every module already writes to ActivityLog
 * (Client, Task, Request, Payment, ...), but the only existing view of it was
 * a per-client tab. This permission gates the new admin-wide monitoring page
 * that lists all of it. Starts with Super Admin and Manager; grant it to any
 * other role in Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'view activity log', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'view activity log')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
