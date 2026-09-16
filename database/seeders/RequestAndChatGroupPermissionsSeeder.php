<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Brings an existing installation up to the request and chat-group permissions.
 *
 * Additive on purpose — it grants and never revokes, so it cannot undo anything
 * an admin set by hand:
 *
 *  - every role keeps what it had before these permissions existed: anyone
 *    could open Requests and file one, so every role gets both;
 *  - creating chat groups starts with Super Admin and Manager.
 *
 *     php artisan db:seed --class=RequestAndChatGroupPermissionsSeeder
 */
class RequestAndChatGroupPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $view   = Permission::firstOrCreate(['name' => 'view requests', 'guard_name' => 'web']);
        $create = Permission::firstOrCreate(['name' => 'create requests', 'guard_name' => 'web']);
        $groups = Permission::firstOrCreate(['name' => 'create chat groups', 'guard_name' => 'web']);

        foreach (Role::all() as $role) {
            $role->givePermissionTo([$view, $create]);
        }

        foreach (['Super Admin', 'Manager'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo($groups);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
