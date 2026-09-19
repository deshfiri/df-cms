<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits task oversight out of 'manage tasks'.
 *
 * 'manage tasks' was also the key to every task in the company, and Sales and
 * Support hold it so they can hand out work — so they could read and edit
 * everyone's tasks. Now:
 *
 *   manage tasks      create tasks, and edit or delete the tasks you created
 *   manage all tasks  see and manage every task — the oversight seat
 *
 * Everyone else sees only the tasks they created or are assigned. Oversight
 * starts with Super Admin and Manager; grant it to anyone else in Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage all tasks', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage all tasks')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
