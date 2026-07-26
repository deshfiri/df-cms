<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Services\Portal\PortalJourneyPresenter;

class PortalJourneyController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly PortalJourneyPresenter $presenter,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;
        $stages = $this->presenter->present($client);
        $overallProgress = $this->presenter->overallProgressPercent($client);

        return view('portal.journey.index', compact('stages', 'overallProgress'));
    }
}
