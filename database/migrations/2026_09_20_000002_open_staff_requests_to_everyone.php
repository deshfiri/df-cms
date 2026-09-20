<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Filing a request is now part of having a login.
 *
 * 'view requests' and 'create requests' were granted to every role, so they
 * gated nothing — while any role that missed them (a new or custom one) lost
 * the Requests menu entirely and had no way to ask for anything. Both are
 * removed so the Roles page only lists permissions that still decide something.
 *
 * 'manage requests' stays: it is what lets someone see everyone's requests and
 * approve or reject them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::whereIn('name', ['view requests', 'create requests'])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['view requests', 'create requests'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
