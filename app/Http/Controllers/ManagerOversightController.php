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
    ) {
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()->can('view brand-checklist-overview'), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        // Computed once and shared — the workload tile's count must never
        // disagree with the list it's summarizing.
        $unreviewed = $this->unreviewedPublishedContents();

        return view('manager.oversight', [
            'budgets' => $this->budgetTable(),
            'checklists' => BrandChecklist::with('brand.client')->get(),
            // One query for every brand's per-category item counts — see
            // BrandChecklistProjectionService::categoryCounts(). The full
            // item/submission/file breakdown lives on the dedicated
            // checklist detail page (marketing.checklist), not here.
            'categoryCounts' => $this->projection->categoryCounts(),
            'workload' => $this->departmentWorkload($unreviewed->count()),
            'unreviewed' => $unreviewed,
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
     * not a historical activity log (ActivityLog has no brand_id to filter
     * by, and this is meant to answer "what's piling up", not "what happened").
     */
    private function departmentWorkload(int $unreviewedPublishes): array
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
                'collected' => $withStatus(ContentItem::$categories, ContentItem::STATUS_COLLECTED),
            ],
            'marketing' => [
                // Passed in from index() — the same computed list the
                // "Unreviewed Published Content" table below renders, so
                // this count never disagrees with what that table shows.
                'unreviewed_publishes' => $unreviewedPublishes,
                'pending_corrections' => PendingChange::where('model_type', AdvertisingExpenditure::class)->pending()->count(),
            ],
        ];
    }

    /**
     * Every unreviewed publish across every brand — same shape
     * MarketingBillingController::unreviewedPublishedContents() returns,
     * just global instead of brand-scoped, matching how the rest of this
     * screen (budgets, checklists) is already cross-brand. Read-only; the
     * actual review/revision actions reuse the existing Marketing routes.
     */
    private function unreviewedPublishedContents(): Collection
    {
        $items = PublishedContent::whereNull('reviewed_at')
            ->with(['item:id,title,category,brand_id', 'item.brand:id,name', 'publishedBy:id,name'])
            ->orderBy('published_at')
            ->get();

        // Same exclusion MarketingBillingController::unreviewedPublishedContents()
        // applies — a publication a revision has since been requested
        // against is historical, not actionable. See
        // PublishedContent::annotateReviewStates().
        return PublishedContent::annotateReviewStates($items)
            ->reject(fn (PublishedContent $p) => $p->review_state === PublishedContent::REVIEW_STATE_REVISION_REQUESTED)
            ->values();
    }
}
