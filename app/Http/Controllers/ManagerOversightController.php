<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\ContentItem;
use App\Models\PendingChange;
use App\Models\PublishedContent;
use App\Models\AdvertisingExpenditure;
use App\Services\BrandChecklistHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Manager's cross-brand oversight screen — the first consumer of
 * 'view brand-checklist-overview' (seeded in Phase 0, unused until now).
 * Read-only against Phase 0-3 data except for clearing a checklist hold
 * (Fix I), which goes through BrandChecklistHoldService.
 */
class ManagerOversightController extends Controller
{
    public function __construct(
        private readonly BrandChecklistHoldService $holds,
    ) {
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()->can('view brand-checklist-overview'), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('manager.oversight', [
            'budgets'    => $this->budgetTable(),
            'checklists' => BrandChecklist::with('brand.client')->get(),
            'workload'   => $this->departmentWorkload(),
        ]);
    }

    public function clearHold(Request $request, BrandChecklist $brandChecklist): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ]);

        $result = $this->holds->clearHold($brandChecklist, $request->user(), $data['reason'] ?? null);

        Cache::forget('dash.manager_brand_budgets');

        return response()->json(['success' => true] + $result);
    }

    /** Per-brand budget/spend/remaining/overspend — Brand's own methods do the math (Option 2). */
    private function budgetTable(): \Illuminate\Support\Collection
    {
        return Cache::remember('dash.manager_brand_budgets', 600, function () {
            return Brand::with('client:id,client_name')
                ->get()
                ->map(fn (Brand $brand) => [
                    'brand'             => $brand,
                    'budget'            => $brand->advertisingBudget(),
                    'spent'             => $brand->advertisingSpent(),
                    'remaining'         => $brand->advertisingRemaining(),
                    'is_overspent'      => $brand->isAdvertisingOverspent(),
                    'overspent_amount'  => $brand->advertisingOverspentAmount(),
                ])
                ->filter(fn ($row) => $row['budget'] > 0 || $row['spent'] > 0)
                ->values();
        });
    }

    /**
     * "Who owes work right now" — current pending counts per department,
     * not a historical activity log (ActivityLog has no brand_id to filter
     * by, and this is meant to answer "what's piling up", not "what happened").
     */
    private function departmentWorkload(): array
    {
        $byCategoryStatus = ContentItem::query()
            ->selectRaw('category, status, count(*) as total')
            ->groupBy('category', 'status')
            ->get();

        $owed = fn (array $categories) => $byCategoryStatus
            ->whereIn('category', $categories)
            ->whereIn('status', [ContentItem::STATUS_PENDING, ContentItem::STATUS_IN_PROGRESS, ContentItem::STATUS_NEEDS_REVISION])
            ->sum('total');

        $withStatus = fn (array $categories, string $status) => $byCategoryStatus
            ->whereIn('category', $categories)
            ->where('status', $status)
            ->sum('total');

        return [
            'content' => [
                'owed' => $owed([ContentItem::CATEGORY_RAW_CONTENT, ContentItem::CATEGORY_ADVERTISING_CONTENT]),
            ],
            'designer' => [
                'owed' => $owed([ContentItem::CATEGORY_POSTER]),
            ],
            'smm' => [
                'available' => $withStatus(ContentItem::$categories, ContentItem::STATUS_AVAILABLE),
                'collected'  => $withStatus(ContentItem::$categories, ContentItem::STATUS_COLLECTED),
            ],
            'marketing' => [
                'unreviewed_publishes' => PublishedContent::whereNull('reviewed_at')->count(),
                'pending_corrections'  => PendingChange::where('model_type', AdvertisingExpenditure::class)->pending()->count(),
            ],
        ];
    }
}
