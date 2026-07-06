<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view clients', 'manage clients']);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->hasAnyPermission(['view clients', 'manage clients']);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage clients');
    }

    /**
     * Only Super Admin is unrestricted. Everyone else with "manage clients"
     * (Manager included, plus Sales, Marketing, Support, ...) may only touch a
     * client that's either unassigned or assigned to them — exemption-based
     * rather than an enumerated role list, so it covers any role granted the
     * permission.
     */
    public function update(User $user, Client $client): bool
    {
        if (!$user->hasPermissionTo('manage clients')) {
            return false;
        }
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $client->assigned_to === null || (int) $client->assigned_to === $user->id;
    }

    /**
     * Transferring requires actually owning the client (an unassigned client
     * has no owner to transfer from) — Super Admin still bypasses.
     */
    public function transfer(User $user, Client $client): bool
    {
        if (!$user->hasPermissionTo('manage clients')) {
            return false;
        }
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $client->assigned_to !== null && (int) $client->assigned_to === $user->id;
    }

    /** Bulk (re)assignment from the client list is an admin-level action. */
    public function bulkAssign(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Manager']);
    }

    /**
     * Terminating a client (individually or in bulk) permanently locks its
     * workflow, so it's restricted the same way as bulk (re)assignment.
     */
    public function terminate(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Manager']);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->hasPermissionTo('delete clients');
    }
}
