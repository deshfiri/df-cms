<?php

namespace App\Http\Controllers;

use App\Models\AdvertisingExpenditure;
use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\ContentItem;
use App\Models\PendingChange;
use App\Models\PublishedContent;
use App\Services\BrandChecklistHoldService;
use App\Services\BrandChecklistProjectionService;
use App\Services\Reporting\PanelActivityReport;
use App\Support\BrandScope;
use App\Support\ReportingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        private readonly BrandChecklistProjectionService $projection,
        private readonly PanelActivityReport $activity,
    ) {
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()->can('view brand-checklist-overview'), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $brands = Brand::inWorkflow()->orderBy('name')->get(['id', 'name']);
        $brand = BrandScope::fromRequest($request, $brands);

        // The workload tile is a current, unfiltered-by-period backlog count
        // — it must never be hidden by an old period, so it's computed on
        // its own, separately from the row list below. A selected Brand
        // still narrows it: period and brand are independent filters (see
        // BrandScope) — only the period half of that rule is special here.
        $unreviewedCount = $this->unreviewedPublishedContents(null, $brand->id)->count();

        // Activity, and now the "Unreviewed Published Content" row list
        // itself, follow the selected period (and the selected Brand) —
        // only the workload tile above stays a current, period-unfiltered
        // snapshot.
        $period = ReportingPeriod::fromRequest($request);
        // The brand-wise Activity table below follows the same selection:
        // one row, not the whole company's, once a specific Brand is picked.
        $activityBrands = $brand->isAll() ? $brands : $brands->where('id', $brand->id)->values();

        return view('manager.oversight', [
            'budgets' => $this->budgetTable(),
            'checklists' => BrandChecklist::with('brand.client')->get(),
            // One query for every brand's per-category item counts — see
            // BrandChecklistProjectionService::categoryCounts(). The full
            // item/submission/file breakdown lives on the dedicated
            // checklist detail page (marketing.checklist), not here.
            'categoryCounts' => $this->projection->categoryCounts(),
            'workload' => $this->departmentWorkload($unreviewedCount, $brand->id),
            'unreviewed' => $this->unreviewedPublishedContents($period, $brand->id),
            'period' => $period,
            'brand' => $brand,
            'brands' => $brands,
            'activity' => $this->activity->managerOversight($period, $activityBrands, $brand->id),
            'activityBrands' => $activityBrands,
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

    /**
     * Per-brand budget/spend/remaining/overspend (Option 2). Budget and
     * spent are each computed once per brand here and remaining/overspent
     * derived arithmetically — Brand::advertisingRemaining()/
     * isAdvertisingOverspent()/advertisingOverspentAmount() each re-run
     * advertisingBudget()+advertisingSpent() internally, which would mean
     * 8 queries per brand instead of 2 if called directly in this loop.
     */
    private function budgetTable(): Collection
    {
        return Cache::remember('dash.manager_brand_budgets', 600, function () {
            return Brand::with('client:id,client_name')
                ->get()
                ->map(function (Brand $brand) {
                    $budget = $brand->advertisingBudget();
                    $spent = $brand->advertisingSpent();
                    $remaining = round($budget - $spent, 2);
                    $isOverspent = $remaining < 0;

                    return [
                        'brand' => $brand,
                        'budget' => $budget,
                        'spent' => $spent,
                        'remaining' => $remaining,
                        'is_overspent' => $isOverspent,
                        'overspent_amount' => $isOverspent ? round(abs($remaining), 2) : 0.0,
                    ];
                })
                ->filter(fn ($row) => $row['budget'] > 0 || $row['spent'] > 0)
                ->values();
        });
    }

    /**
     * "Who owes work right now" — current pending counts per department,
     * not a historical activity log. Never period-filtered (there is no
     * "when" for a live backlog), but a selected Brand still narrows it —
     * period and brand are independent filters (see BrandScope).
     */
    private function departmentWorkload(int $unreviewedPublishes, ?int $brandId = null): array
    {
        $byCategoryStatus = ContentItem::query()
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
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
                'collected' => $withStatus(ContentItem::$categories, ContentItem::STATUS_COLLECTED),
            ],
            'marketing' => [
                // The current, unfiltered backlog — deliberately a separate
                // query from the "Unreviewed Published Content" table below,
                // which now follows the selected period and so may show
                // fewer rows than this count when a past period is selected.
                'unreviewed_publishes' => $unreviewedPublishes,
                'pending_corrections' => PendingChange::where('model_type', AdvertisingExpenditure::class)->pending()
                    ->when($brandId !== null, fn ($q) => $q->whereIn(
                        'model_id',
                        AdvertisingExpenditure::where('brand_id', $brandId)->select('id')
                    ))
                    ->count(),
            ],
        ];
    }

    /**
     * Every unreviewed publish across every brand — same shape
     * MarketingBillingController::unreviewedPublishedContents() returns,
     * just global instead of brand-scoped, matching how the rest of this
     * screen (budgets, checklists) is already cross-brand. Read-only; the
     * actual review/revision actions reuse the existing Marketing routes.
     *
     * With no $period, this is the full, unfiltered backlog (the workload
     * tile's count). With a $period, it's filtered to that selected
     * Daily/Monthly/Yearly window by published_at — the moment each
     * publication actually happened — for the row list shown on the page.
     * $brandId narrows either shape to one brand, independently of $period.
     */
    private function unreviewedPublishedContents(?ReportingPeriod $period = null, ?int $brandId = null): Collection
    {
        $query = PublishedContent::whereNull('reviewed_at')
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->with(['item:id,title,category,brand_id', 'item.brand:id,name', 'publishedBy:id,name'])
            ->orderBy('published_at');

        if ($period) {
            [$since, $until] = $period->bounds();
            $query->where('published_at', '>=', $since)->where('published_at', '<', $until);
        }

        $items = $query->get();

        // Same exclusion MarketingBillingController::unreviewedPublishedContents()
        // applies — a publication a revision has since been requested
        // against is historical, not actionable. See
        // PublishedContent::annotateReviewStates().
        return PublishedContent::annotateReviewStates($items)
            ->reject(fn (PublishedContent $p) => $p->review_state === PublishedContent::REVIEW_STATE_REVISION_REQUESTED)
            ->values();
    }
}
