<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\BrandChecklistProjectionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The shared, read-only Brand content checklist/progress view — one page,
 * reachable from Manager Oversight, Marketing's Brand page, and the Raw
 * Content/Designer/SMM panels alike. It never performs a workflow action;
 * every submit/collect/publish/review/revision action stays on the
 * existing operational panels and routes. See
 * App\Services\BrandChecklistProjectionService for the projection itself.
 */
class BrandChecklistController extends Controller
{
    public function __construct(
        private readonly BrandChecklistProjectionService $projection,
    ) {}

    public function show(Request $request, Brand $brand): View
    {
        $this->authorizeRead($request);

        return view('checklist.show', $this->projection->detail($brand));
    }

    /**
     * Read access only — matches ContentItemController::authorizeView()
     * (any panel role, or Manager oversight) plus Marketing's own existing
     * review/charge/expenditure permissions, since Marketing is already
     * responsible for Brand-level review and financial oversight. Nothing
     * here grants write access to any department's own workflow actions —
     * those stay gated exactly as they already are on the panels/routes
     * that perform them.
     */
    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()->hasAnyPermission([
            'view brand-checklist-overview', 'view raw-content-panel', 'view designer-panel', 'view smm-panel',
            'manage publishing-review', 'manage content-charges', 'manage advertising-expenditure',
        ]), 403);
    }
}
