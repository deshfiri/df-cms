<?php

namespace App\Policies;

use App\Models\AdCampaign;
use App\Models\User;

class AdCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view ads', 'manage ads']);
    }

    public function view(User $user, AdCampaign $campaign): bool
    {
        return $user->hasAnyPermission(['view ads', 'manage ads']) || (int) $campaign->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage ads');
    }

    public function update(User $user, AdCampaign $campaign): bool
    {
        return $user->hasPermissionTo('manage ads') || (int) $campaign->assigned_to === $user->id;
    }

    public function assign(User $user): bool
    {
        return $user->hasPermissionTo('manage ads');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Manager']);
    }
}
