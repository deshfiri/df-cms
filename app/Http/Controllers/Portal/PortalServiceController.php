<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Services\Portal\PortalServiceGroupingService;

class PortalServiceController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly PortalServiceGroupingService $grouping,
    ) {}

    public function index()
    {
        $services = $this->grouping->groupByDepartment($this->portalUser()->client);

        return view('portal.services.index', compact('services'));
    }
}
