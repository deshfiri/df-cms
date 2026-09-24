<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings → Forbidden Words, and who receives the summary when someone
 * uses one in the internal chat.
 *
 * Deliberately its own permission rather than reusing 'monitor chats': that
 * one grants full read access to every conversation, which is a much
 * bigger grant than "manage the blocked-word list and get told when it's
 * tripped". Starts with Super Admin only; grant it to any other role in
 * Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage chat moderation', 'guard_name' => 'web']);

        Role::where('name', 'Super Admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage chat moderation')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
