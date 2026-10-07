<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\PublishedContent;
use App\Services\Reporting\PanelActivityReport;
use App\Services\Storage\StoredFileResponse;
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
     * The queue is always current (unfiltered). The Activity section beside it
     * is the only part that follows the Daily/Monthly/Yearly selection.
     */
    public function rawContent(Request $request): View|JsonResponse
    {
        abort_unless($request->user()->can('view raw-content-panel'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->itemsJson($request, ContentItem::contentPanelCategories());
        }

        $period = ReportingPeriod::fromRequest($request);

        return view('panels.raw-content', [
            'brands' => $this->brandsWithChecklist(),
            'period' => $period,
            'activity' => $this->activity->rawContent($period),
        ]);
    }

    public function designer(Request $request): View|JsonResponse
    {
        abort_unless($request->user()->can('view designer-panel'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->itemsJson($request, [ContentItem::CATEGORY_POSTER]);
        }

        $period = ReportingPeriod::fromRequest($request);

        return view('panels.designer', [
            'brands' => $this->brandsWithChecklist(),
            'period' => $period,
            'activity' => $this->activity->designer($period),
        ]);
    }

    public function smm(Request $request): View
    {
        abort_unless($request->user()->can('view smm-panel'), 403);

        $period = ReportingPeriod::fromRequest($request);

        return view('panels.smm', [
            'period' => $period,
            'activity' => $this->activity->smm($period),
        ]);
    }

    public function smmAvailable(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage smm-collection'), 403);

        // The server-side SMM-eligibility gate, read-side: a submission
        // Marketing hasn't approved simply never appears here — the same
        // rule ContentItemService::collect()/publish() enforce on write, so
        // this list and what's actually collectible can never disagree.
        $items = ContentItem::where('status', ContentItem::STATUS_AVAILABLE)
            ->whereHas('latestSubmissionRelation.approval')
            ->with(['brand:id,name', 'product:id,name', 'latestSubmissionRelation'])
            ->latest()
            ->get()
            ->map(fn (ContentItem $item) => $this->presentItem($item, ['submission' => $item->latestSubmissionRelation]));

        return response()->json(['data' => $items]);
    }

    public function smmCollected(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage smm-collection'), 403);

        $items = ContentItem::where('status', ContentItem::STATUS_COLLECTED)
            ->with(['brand:id,name', 'product:id,name', 'latestCollectionRelation.submission', 'latestCollectionRelation.collectedBy:id,name'])
            ->latest()
            ->get()
            ->map(fn (ContentItem $item) => $this->presentItem($item, ['collection' => $item->latestCollectionRelation]));

        return response()->json(['data' => $items]);
    }

    public function smmPublished(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage published-content'), 403);

        $published = PublishedContent::with(['item:id,title,category,brand_id', 'item.brand:id,name', 'submission', 'publishedBy:id,name', 'reviewedBy:id,name'])
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->brand_id))
            ->orderByDesc('published_at')
            ->get();

        // Publication history always stays visible here (see the class
        // docblock) — review_state only changes the badge, never which
        // rows appear. See PublishedContent::annotateReviewStates().
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

        return view('panels.marketing', ['brands' => $this->brandsWithChecklist()]);
    }

    /**
     * Marketing's current, actionable pre-publish queue — every item whose
     * latest submission is sitting at `available` with no approval row yet.
     * Cross-brand, mirroring Raw Content/Designer/SMM's own "what's on my
     * plate everywhere" shape. Never period-filtered — see
     * ReportingPeriod's own docblock and PanelController::marketingWorkload()
     * for why current actionable work and historical reporting stay two
     * separate concepts.
     */
    public function marketingPendingCheck(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $items = ContentItem::where('status', ContentItem::STATUS_AVAILABLE)
            ->whereHas('latestSubmissionRelation', fn ($q) => $q->whereDoesntHave('approval'))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->brand_id))
            ->withCount('submissions')
            ->with(['brand:id,name', 'product:id,name', 'latestSubmissionRelation.submittedBy:id,name'])
            ->latest()
            ->get()
            ->map(fn (ContentItem $item) => $this->presentItem($item, [
                'submission' => $item->latestSubmissionRelation,
                'version' => $item->submissions_count,
            ]));

        return response()->json(['data' => $items]);
    }

    /**
     * The brand-wise Marketing workload dashboard. Historical activity is
     * bounded to the selected Daily/Monthly/Yearly period and comes from the
     * shared PanelActivityReport, so Manager Oversight reads the same numbers.
     * The current queues (Pending Pre-Publish, Pending Final Review) are never
     * period-filtered, so a historical filter never hides urgent work.
     */
    public function marketingWorkload(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $period = ReportingPeriod::fromRequest($request);
        $brands = $this->brandsWithChecklist();
        $history = $this->activity->marketingBrands($period, $brands);
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

    private function itemsJson(Request $request, array $categories): JsonResponse
    {
        $items = ContentItem::whereIn('category', $categories)
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
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

    private function brandsWithChecklist()
    {
        return Brand::whereHas('checklist')->orderBy('name')->get(['id', 'name']);
    }
}
