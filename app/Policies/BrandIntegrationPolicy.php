<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\User;

/**
 * Server-side gate for everything under Marketing.
 *
 * Two questions, always in this order: may this person touch ads at all, and
 * may they touch *this* brand's ads. The second is what keeps one client's
 * integrations away from another's, and it is never left to the frontend.
 *
 * Client visibility is delegated to ClientPolicy so there is one definition of
 * "this user may see this client" rather than a second, drifting copy.
 */
class BrandIntegrationPolicy
{
    /** Reading dashboards, campaigns, insights. */
    public function view(User $user, Brand $brand): bool
    {
        return $user->hasAnyPermission(['view ads', 'manage ads'])
            && $this->canReachBrand($user, $brand);
    }

    /** Connecting, disconnecting, choosing resources, forcing a sync. */
    public function manage(User $user, Brand $brand): bool
    {
        return $user->hasPermissionTo('manage ads')
            && $this->canReachBrand($user, $brand);
    }

    public function viewIntegration(User $user, BrandIntegration $integration): bool
    {
        return $integration->brand !== null && $this->view($user, $integration->brand);
    }

    public function manageIntegration(User $user, BrandIntegration $integration): bool
    {
        return $integration->brand !== null && $this->manage($user, $integration->brand);
    }

    /**
     * A brand is reachable when its client is.
     *
     * Super Admin short-circuits through Gate::before, so this only decides for
     * everyone else.
     */
    private function canReachBrand(User $user, Brand $brand): bool
    {
        $client = $brand->client;

        return $client !== null && $user->can('view', $client);
    }
}
