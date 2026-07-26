<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Services\Portal\PortalDashboardService;

class PortalDashboardController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly PortalDashboardService $dashboardService,
    ) {}

    public function index()
    {
        $portalUser = $this->portalUser();
        $client = $portalUser->client;
        $dashboard = $this->dashboardService->buildDashboard($client);

        return view('portal.dashboard', compact('portalUser', 'client', 'dashboard'));
    }
}
