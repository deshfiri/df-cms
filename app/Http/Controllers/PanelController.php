<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\PublishedContent;
use App\Services\Storage\StoredFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $items = ContentItem::where('status', ContentItem::STATUS_AVAILABLE)
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
