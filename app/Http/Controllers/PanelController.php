<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\PublishedContent;
use App\Models\User;
use App\Services\Storage\StoredFileResponse;
use App\Support\ReportingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cross-brand landing pages for the Raw Content, Designer and SMM panels.
 * ContentItemController's own routes are brand-scoped (creating an item
 * needs a specific brand's checklist), but a worker's actual queue spans
 * every brand they touch — these list across all of them instead.
 */
class PanelController extends Controller
{
    public function rawContent(Request $request): View|JsonResponse
    {
        abort_unless($request->user()->can('view raw-content-panel'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->itemsJson($request, ContentItem::contentPanelCategories());
        }

        return view('panels.raw-content', ['brands' => $this->brandsWithChecklist()]);
    }

    public function designer(Request $request): View|JsonResponse
    {
        abort_unless($request->user()->can('view designer-panel'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->itemsJson($request, [ContentItem::CATEGORY_POSTER]);
        }

        return view('panels.designer', ['brands' => $this->brandsWithChecklist()]);
    }

    public function smm(Request $request): View
    {
        abort_unless($request->user()->can('view smm-panel'), 403);

        return view('panels.smm');
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
     * The brand-wise Marketing workload dashboard. Two different kinds of
     * number sit side by side here, and they are deliberately different:
     *
     *  - HISTORICAL EVENT COUNTS (Received, Handed Over, Returned for Final
     *    Check, Completed, Revision Requested) count real business events.
     *    Each is bounded to the selected Daily/Monthly/Yearly Asia/Dhaka
     *    period and timestamped by the event it counts. V1 + V2 = two
     *    Received events.
     *  - CURRENT QUEUE COUNTS (Pending Pre-Publish, Pending Final Review) are
     *    what is actionable right now. They are never period-filtered, so a
     *    historical filter can never hide today's urgent item.
     *
     * Every metric is one grouped query across all brands at once, so the
     * query count does not grow with the number of brands.
     */
    public function marketingWorkload(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $period = ReportingPeriod::fromRequest($request);
        [$since, $until] = $period->bounds();

        $brands = $this->brandsWithChecklist();

        $received = $this->countByBrand(
            $this->betweenPeriod(
                $this->liveItemJoin(DB::table('content_item_submissions'), 'content_item_submissions.content_item_id'),
                'content_item_submissions.created_at', $since, $until,
            ),
        );

        $handedOver = $this->countByBrand(
            $this->betweenPeriod(
                $this->liveItemJoin(DB::table('content_item_submission_approvals'), 'content_item_submission_approvals.content_item_id'),
                'content_item_submission_approvals.approved_at', $since, $until,
            ),
        );

        $returned = $this->countByBrand(
            $this->betweenPeriod(
                $this->liveItemJoin(DB::table('published_contents'), 'published_contents.content_item_id'),
                'published_contents.published_at', $since, $until,
            ),
        );

        // Completed means the kind of review that notifies the Manager: a review
        // of the item's CURRENT version only. A review of a superseded version
        // leaves the current cycle open (no ContentPublishedAndReviewed is sent
        // for it), so it must not count as a completed cycle either.
        $completed = $this->countByBrand(
            $this->betweenPeriod(
                $this->liveItemJoin(DB::table('published_contents'), 'published_contents.content_item_id')
                    ->join('content_item_submissions as reviewed_version', 'reviewed_version.id', '=', 'published_contents.submission_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')
                        ->from('content_item_submissions as newer_version')
                        ->whereColumn('newer_version.content_item_id', 'reviewed_version.content_item_id')
                        ->whereColumn('newer_version.id', '>', 'reviewed_version.id')),
                'published_contents.reviewed_at', $since, $until,
            ),
        );

        // Pre-publish revisions only: Marketing sending a submission back while
        // it is still `available`. Revisions made after SMM collected or
        // published the work belong to SMM and post-publish review, not here.
        $marketingUserIds = User::role('Marketing')->pluck('id');
        $revisionRequested = $this->countByBrand(
            $this->betweenPeriod(
                $this->liveItemJoin(DB::table('content_item_revisions'), 'content_item_revisions.content_item_id')
                    ->where('content_item_revisions.previous_status', ContentItem::STATUS_AVAILABLE)
                    ->whereIn('content_item_revisions.requested_by', $marketingUserIds),
                'content_item_revisions.created_at', $since, $until,
            ),
        );

        // Current, not period-filtered. The latest version of an item that is
        // waiting for Marketing with no approval yet. An earlier version that
        // was sent back is never counted here.
        $pendingPrePublish = ContentItem::query()
            ->where('status', ContentItem::STATUS_AVAILABLE)
            ->whereHas('latestSubmissionRelation', fn ($q) => $q->whereDoesntHave('approval'))
            ->selectRaw('brand_id, count(*) as total')
            ->groupBy('brand_id')
            ->pluck('total', 'brand_id');

        // Uses the same authoritative review state as the Publishing Review
        // queue (PublishedContent::annotateReviewStates()), so a publication
        // already sent back for revision is not counted as waiting for
        // Marketing. Only unreviewed rows are loaded, so this is bounded by the
        // real backlog, not by all history.
        $pendingFinalReview = PublishedContent::annotateReviewStates(
            PublishedContent::query()
                ->whereNull('reviewed_at')
                ->whereHas('item')
                ->get(['id', 'content_item_id', 'brand_id', 'published_at', 'reviewed_at'])
        )
            ->filter(fn (PublishedContent $publication) => $publication->review_state === PublishedContent::REVIEW_STATE_AWAITING_REVIEW)
            ->countBy('brand_id');

        $rows = $brands->map(function (Brand $brand) use (
            $received, $handedOver, $returned, $completed, $revisionRequested, $pendingPrePublish, $pendingFinalReview
        ) {
            return [
                'brand_id' => $brand->id,
                'brand' => $brand->name,
                'received' => (int) ($received[$brand->id] ?? 0),
                'pending_pre_publish' => (int) ($pendingPrePublish[$brand->id] ?? 0),
                'handed_over' => (int) ($handedOver[$brand->id] ?? 0),
                'returned_for_final_check' => (int) ($returned[$brand->id] ?? 0),
                'pending_final_review' => (int) ($pendingFinalReview[$brand->id] ?? 0),
                'completed' => (int) ($completed[$brand->id] ?? 0),
                'revision_requested' => (int) ($revisionRequested[$brand->id] ?? 0),
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
            'totals' => [
                'received' => $rows->sum('received'),
                'handed_over' => $rows->sum('handed_over'),
                'returned_for_final_check' => $rows->sum('returned_for_final_check'),
                'completed' => $rows->sum('completed'),
                'revision_requested' => $rows->sum('revision_requested'),
                'pending_pre_publish' => $rows->sum('pending_pre_publish'),
                'pending_final_review' => $rows->sum('pending_final_review'),
            ],
        ]);
    }

    /**
     * Joins a table to its content item and keeps only live (not soft-deleted)
     * items, so a deleted item never inflates a count. Every metric above
     * starts here.
     */
    private function liveItemJoin(Builder $query, string $itemIdColumn): Builder
    {
        return $query->join('content_items', 'content_items.id', '=', $itemIdColumn)
            ->whereNull('content_items.deleted_at');
    }

    /**
     * Half-open [since, until). An event at exactly the next period's first
     * second belongs to that next period, never to both. ReportingPeriod's
     * own bounds are already [since, until), so this must not be inclusive.
     */
    private function betweenPeriod(Builder $query, string $column, CarbonInterface $since, CarbonInterface $until): Builder
    {
        return $query->where($column, '>=', $since)->where($column, '<', $until);
    }

    /** One grouped query: brand_id => count, for every brand with a matching row. */
    private function countByBrand(Builder $query): Collection
    {
        return $query->selectRaw('content_items.brand_id as brand_id, count(*) as total')
            ->groupBy('content_items.brand_id')
            ->pluck('total', 'brand_id');
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
