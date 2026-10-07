<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemStageOwner;
use App\Models\ContentItemSubmission;
use App\Models\PublishedContent;
use App\Models\User;
use App\Services\Reporting\PanelActivityReport;
use App\Services\Storage\StoredFileResponse;
use App\Services\Workflow\StageOwnershipService;
use App\Support\BrandScope;
use App\Support\ReportingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Cross-brand landing pages for the Raw Content, Designer and SMM panels.
 * ContentItemController's own routes are brand-scoped (creating an item
 * needs a specific brand's checklist), but a worker's actual queue spans
 * every brand they touch — these list across all of them instead.
 */
class PanelController extends Controller
{
    public function __construct(private readonly PanelActivityReport $activity) {}

    /**
     * The queue follows the selected Daily/Monthly/Yearly period AND the
     * selected Brand (All Brands when none is chosen) — same as the Activity
     * section beside it. See itemsJson() for which timestamp a row is
     * filtered by, and BrandScope for how the brand is authorized.
     */
    public function rawContent(Request $request): View|JsonResponse
    {
        abort_unless($request->user()->can('view raw-content-panel'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->itemsJson($request, ContentItem::contentPanelCategories());
        }

        $brands = $this->brandsWithChecklist();
        $period = ReportingPeriod::fromRequest($request);
        $brand = BrandScope::fromRequest($request, $brands);

        return view('panels.raw-content', [
            'brands' => $brands,
            'period' => $period,
            'brand' => $brand,
            'activity' => $this->activity->rawContent($period, $brand->id),
        ]);
    }

    public function designer(Request $request): View|JsonResponse
    {
        abort_unless($request->user()->can('view designer-panel'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->itemsJson($request, [ContentItem::CATEGORY_POSTER]);
        }

        $brands = $this->brandsWithChecklist();
        $period = ReportingPeriod::fromRequest($request);
        $brand = BrandScope::fromRequest($request, $brands);

        return view('panels.designer', [
            'brands' => $brands,
            'period' => $period,
            'brand' => $brand,
            'activity' => $this->activity->designer($period, $brand->id),
        ]);
    }

    public function smm(Request $request): View
    {
        abort_unless($request->user()->can('view smm-panel'), 403);

        $brands = Brand::inWorkflow()
            ->with(['products' => fn ($q) => $q->select('id', 'brand_id', 'name')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name']);
        $period = ReportingPeriod::fromRequest($request);
        $brand = BrandScope::fromRequest($request, $brands);

        return view('panels.smm', [
            'period' => $period,
            'brand' => $brand,
            'brands' => $brands,
            'activity' => $this->activity->smm($period, $brand->id),
            // Each SMM user sees their own conversations. Ownership is per user, never per panel.
            'conversations' => $this->activity->smmConversations($period, $request->user(), $brand->id),
        ]);
    }

    public function smmAvailable(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage smm-collection'), 403);

        [$since, $until] = ReportingPeriod::fromRequest($request)->bounds();
        $brand = BrandScope::fromRequest($request, Brand::inWorkflow()->get(['id', 'name']));

        // The server-side SMM-eligibility gate, read-side: a submission
        // Marketing hasn't approved simply never appears here — the same
        // rule ContentItemService::collect()/publish() enforce on write, so
        // this list and what's actually collectible can never disagree.
        // Filtered by the exact submission's handover (approved_at), not the
        // original submission's created_at — a row belongs to the period in
        // which it became available to SMM, not the period it was first made.
        $items = ContentItem::where('status', ContentItem::STATUS_AVAILABLE)
            ->whereHas('latestSubmissionRelation.approval', fn ($q) => $q->where('approved_at', '>=', $since)->where('approved_at', '<', $until))
            ->tap(fn ($q) => $brand->apply($q))
            ->with(['brand:id,name', 'product:id,name', 'latestSubmissionRelation'])
            ->latest()
            ->get()
            ->map(fn (ContentItem $item) => $this->presentItem($item, ['submission' => $item->latestSubmissionRelation]));

        return response()->json(['data' => $items]);
    }

    public function smmCollected(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage smm-collection'), 403);

        [$since, $until] = ReportingPeriod::fromRequest($request)->bounds();
        $brand = BrandScope::fromRequest($request, Brand::inWorkflow()->get(['id', 'name']));

        // Filtered by collected_at — the moment SMM claimed this exact
        // submission, not when it was originally submitted.
        $items = ContentItem::where('status', ContentItem::STATUS_COLLECTED)
            ->whereHas('latestCollectionRelation', fn ($q) => $q->where('collected_at', '>=', $since)->where('collected_at', '<', $until))
            ->tap(fn ($q) => $brand->apply($q))
            ->with(['brand:id,name', 'product:id,name', 'latestCollectionRelation.submission', 'latestCollectionRelation.collectedBy:id,name'])
            ->latest()
            ->get()
            ->map(fn (ContentItem $item) => $this->presentItem($item, ['collection' => $item->latestCollectionRelation]));

        return response()->json(['data' => $items]);
    }

    public function smmPublished(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage published-content'), 403);

        [$since, $until] = ReportingPeriod::fromRequest($request)->bounds();
        $brand = BrandScope::fromRequest($request, Brand::inWorkflow()->get(['id', 'name']));

        $published = PublishedContent::with(['item:id,title,category,brand_id', 'item.brand:id,name', 'submission', 'publishedBy:id,name', 'reviewedBy:id,name'])
            ->tap(fn ($q) => $brand->apply($q))
            ->where('published_at', '>=', $since)
            ->where('published_at', '<', $until)
            ->orderByDesc('published_at')
            ->get();

        // review_state only changes the badge, never which rows appear —
        // see PublishedContent::annotateReviewStates(). Which rows appear at
        // all is now governed by the selected period, filtered above.
        $published = PublishedContent::annotateReviewStates($published)
            ->map(fn (PublishedContent $p) => [
                'id' => $p->id,
                'content_item_id' => $p->content_item_id,
                'brand_id' => $p->brand_id,
                'title' => $p->item->title,
                'category' => $p->item->category,
                'brand' => $p->item->brand->name ?? '—',
                'facebook_post_url' => $p->facebook_post_url,
                'published_by' => $p->publishedBy->name ?? '—',
                'published_at' => $p->published_at?->format('d M Y, h:i A'),
                'reviewed_at' => $p->reviewed_at?->format('d M Y, h:i A'),
                'reviewed_by' => $p->reviewedBy->name ?? null,
                'is_reviewed' => $p->isReviewed(),
                'review_state' => $p->review_state,
            ]);

        return response()->json(['data' => $published]);
    }

    public function marketing(Request $request): View
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $brands = $this->brandsWithChecklist();

        return view('panels.marketing', [
            'brands' => $brands,
            // The Brand filter select needs its own validated current
            // selection too, so a reload preserves it (see Marketing's own
            // JS periodParams()/syncPeriodInputs()).
            'brand' => BrandScope::fromRequest($request, $brands),
            // Who the handover and revision forms may name. The server re-checks eligibility.
            'smmUsers' => $this->eligibleUsers('Social Media Manager'),
            'makerUsers' => User::where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Content', 'Design']))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Marketing's pre-publish queue — every item whose latest submission is
     * sitting at `available` with no approval row yet, filtered to the
     * selected Daily/Monthly/Yearly period by that submission's created_at
     * (the exact moment it arrived for Marketing to check), cross-brand,
     * mirroring Raw Content/Designer/SMM's own "what's on my plate
     * everywhere" shape. See marketingWorkload()/marketingCurrentQueues()
     * for the separate, deliberately unfiltered current-backlog tile count.
     */
    public function marketingPendingCheck(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        [$since, $until] = ReportingPeriod::fromRequest($request)->bounds();
        $brand = BrandScope::fromRequest($request, $this->brandsWithChecklist());

        $items = ContentItem::where('status', ContentItem::STATUS_AVAILABLE)
            ->whereHas('latestSubmissionRelation', fn ($q) => $q->whereDoesntHave('approval')
                ->where('created_at', '>=', $since)->where('created_at', '<', $until))
            ->tap(fn ($q) => $brand->apply($q))
            ->withCount('submissions')
            ->with(['brand:id,name', 'product:id,name', 'latestSubmissionRelation.submittedBy:id,name'])
            ->latest()
            ->get();

        // Who owns each version's pre-publish stage, in one query for the whole queue.
        $owners = ContentItemStageOwner::with('user:id,name')
            ->whereIn('active_ref', $items->map(fn ($item) => StageOwnershipService::pre_publish($item->latestSubmissionRelation->id))->all())
            ->get()
            ->keyBy('active_ref');

        $rows = $items->map(function (ContentItem $item) use ($owners, $request) {
            $owner = $owners->get(StageOwnershipService::pre_publish($item->latestSubmissionRelation->id));

            return $this->presentItem($item, [
                'submission' => $item->latestSubmissionRelation,
                'version' => $item->submissions_count,
                'owner' => $owner ? [
                    'user_id' => $owner->user_id,
                    'name' => $owner->user?->name,
                    'source' => $owner->source,
                    'is_me' => (int) $owner->user_id === (int) $request->user()->id,
                ] : null,
            ]);
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * The brand-wise Marketing workload dashboard. Historical activity is
     * bounded to the selected Daily/Monthly/Yearly period and comes from the
     * shared PanelActivityReport, so Manager Oversight reads the same numbers.
     * The current queues (Pending Pre-Publish, Pending Final Review) are never
     * period-filtered, so a historical filter never hides urgent work — but a
     * selected Brand still narrows every row shown here, current tiles
     * included (period and brand are independent filters; see BrandScope).
     */
    public function marketingWorkload(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $period = ReportingPeriod::fromRequest($request);
        $brands = $this->brandsWithChecklist();
        $brand = BrandScope::fromRequest($request, $brands);
        // A specific brand narrows which rows are built at all (not just
        // their values) — "totals" then naturally collapses to that one
        // brand's own row instead of the whole company's.
        if (! $brand->isAll()) {
            $brands = $brands->where('id', $brand->id)->values();
        }
        $history = $this->activity->marketingBrands($period, $brands, $brand->id);
        [$pendingPrePublish, $pendingFinalReview] = $this->marketingCurrentQueues();

        $rows = $brands->map(function (Brand $brand) use ($history, $pendingPrePublish, $pendingFinalReview) {
            $counts = $history['rows'][$brand->id];

            return [
                'brand_id' => $brand->id,
                'brand' => $brand->name,
                'received' => $counts['received'],
                'pending_pre_publish' => (int) ($pendingPrePublish[$brand->id] ?? 0),
                'handed_over' => $counts['handed_over'],
                'returned_for_final_check' => $counts['returned_for_final_check'],
                'pending_final_review' => (int) ($pendingFinalReview[$brand->id] ?? 0),
                'completed' => $counts['completed'],
                'revision_requested' => $counts['revision_requested'],
            ];
        })
            // Brands with current pending work float to the top (weighted far
            // above the tiebreaker so it always wins), then recent volume.
            // Nothing is hidden; a brand with no activity is just ordered last.
            ->sortByDesc(fn ($row) => ($row['pending_pre_publish'] + $row['pending_final_review']) * 1_000_000 + $row['received'])
            ->values();

        return response()->json([
            'data' => $rows,
            'period' => [
                'period' => $period->period,
                'selected' => $period->selected,
                'label' => $period->label,
            ],
            'totals' => $history['totals'] + [
                'pending_pre_publish' => $rows->sum('pending_pre_publish'),
                'pending_final_review' => $rows->sum('pending_final_review'),
            ],
        ]);
    }

    /**
     * The current, actionable Marketing queues, deliberately unfiltered by any
     * period. Pending Pre-Publish: the latest version waiting for Marketing
     * with no approval yet. Pending Final Review: publications that Marketing
     * has not reviewed and has not sent back, using the same review state as
     * the Publishing Review queue.
     *
     * @return array{0: Collection<int|string, int>, 1: Collection<int|string, int>}
     */
    private function marketingCurrentQueues(): array
    {
        $pendingPrePublish = ContentItem::query()
            ->where('status', ContentItem::STATUS_AVAILABLE)
            ->whereHas('latestSubmissionRelation', fn ($q) => $q->whereDoesntHave('approval'))
            ->selectRaw('brand_id, count(*) as total')
            ->groupBy('brand_id')
            ->pluck('total', 'brand_id');

        // Only unreviewed rows are loaded, so this is bounded by the real backlog.
        $pendingFinalReview = PublishedContent::annotateReviewStates(
            PublishedContent::query()
                ->whereNull('reviewed_at')
                ->whereHas('item')
                ->get(['id', 'content_item_id', 'brand_id', 'published_at', 'reviewed_at'])
        )
            ->filter(fn (PublishedContent $publication) => $publication->review_state === PublishedContent::REVIEW_STATE_AWAITING_REVIEW)
            ->countBy('brand_id');

        return [$pendingPrePublish, $pendingFinalReview];
    }

    /**
     * The shared Raw Content / Designer row list, filtered to the selected
     * Daily/Monthly/Yearly period. A row belongs to the period in which its
     * latest version was submitted — a resubmission's V2 belongs to the
     * period V2 was submitted in, not when the item was first created. An
     * item with no submission yet (freshly created, still pending) falls
     * back to its own created_at so it isn't simply unreachable by any period.
     */
    private function itemsJson(Request $request, array $categories): JsonResponse
    {
        [$since, $until] = ReportingPeriod::fromRequest($request)->bounds();
        $brand = BrandScope::fromRequest($request, $this->brandsWithChecklist());

        $items = ContentItem::whereIn('category', $categories)
            ->tap(fn ($q) => $brand->apply($q))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->where(function ($q) use ($since, $until) {
                $q->whereHas('latestSubmissionRelation', fn ($q2) => $q2->where('created_at', '>=', $since)->where('created_at', '<', $until))
                    ->orWhere(function ($q2) use ($since, $until) {
                        $q2->whereDoesntHave('submissions')->where('created_at', '>=', $since)->where('created_at', '<', $until);
                    });
            })
            ->with(['brand:id,name', 'product:id,name', 'latestSubmissionRelation'])
            ->latest()
            ->get()
            ->map(fn (ContentItem $item) => $this->presentItem($item, ['submission' => $item->latestSubmissionRelation]));

        return response()->json(['data' => $items]);
    }

    private function presentItem(ContentItem $item, array $extra = []): array
    {
        // Cheap, I/O-free extension check only — whether View renders at
        // all. The actual security decision happens server-side, on the
        // real detected MIME, the moment someone clicks it — see
        // ContentItemController::previewSubmission() and
        // StoredFileResponse::looksPreviewable()'s own docblock.
        if (($extra['submission'] ?? null) instanceof ContentItemSubmission) {
            $extra['submission']->setAttribute('previewable', StoredFileResponse::looksPreviewable($extra['submission']->file_path));
            $extra['submission']->syncOriginalAttribute('previewable');
        }

        return array_merge([
            'id' => $item->id,
            'brand_id' => $item->brand_id,
            'brand' => $item->brand->name ?? '—',
            'product' => $item->product->name ?? null,
            'category' => $item->category,
            'title' => $item->title,
            'status' => $item->status,
            'created_at' => $item->created_at?->format('d M Y, h:i A'),
        ], $extra);
    }

    /** Active users holding a role, for assignee pickers. */
    private function eligibleUsers(string $role)
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', $role))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function brandsWithChecklist()
    {
        return Brand::inWorkflow()->orderBy('name')->get(['id', 'name']);
    }
}
