<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Services\ContentItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shared brand checklist's content items — Raw Content and Designer
 * panels (Phase 2) both submit through this same controller, told apart by
 * category. See the SRS integration plan's Fix A for the category → role
 * mapping and Phase 1 for this controller's scope.
 */
class ContentItemController extends Controller
{
    public function __construct(
        private readonly ContentItemService $service,
    ) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeView($request);

        $items = $brand->checklist
            ? $brand->checklist->items()
                ->with(['product:id,name', 'createdBy:id,name'])
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->latest()
                ->get()
            : collect();

        return response()->json([
            'data'          => $items,
            'checklist'     => $brand->checklist,
            'on_hold'       => (bool) $brand->checklist?->isOnHold(),
            'on_hold_reason' => $brand->checklist?->on_hold_reason,
        ]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate([
            'category'   => ['required', Rule::in(ContentItem::$categories)],
            'title'      => ['required', 'string', 'max:200'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('brand_id', $brand->id)],
        ]);

        $this->authorizeCategory($request, $data['category']);

        $item = $this->service->create($brand, $data, $request->user());

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function submit(Request $request, Brand $brand, ContentItem $contentItem): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);
        $this->authorizeCategory($request, $contentItem->category);

        $data = $request->validate([
            'link_url' => ['nullable', 'string', 'max:2048', 'url'],
            'file'     => ['nullable', 'file', 'max:20480'],
        ]);
        $data['file'] = $request->file('file');

        $submission = $this->service->submit($contentItem, $data, $request->user());

        return response()->json(['success' => true, 'data' => $submission->load('submittedBy:id,name')]);
    }

    public function requestRevision(Request $request, Brand $brand, ContentItem $contentItem): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);
        $this->authorizeReviewer($request);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $revision = $this->service->requestRevision($contentItem, $data, $request->user());

        return response()->json(['success' => true, 'data' => $revision]);
    }

    /** SMM explicitly claims the item's current submission as theirs to publish — see Fix B. */
    public function collect(Request $request, Brand $brand, ContentItem $contentItem): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);
        abort_unless($request->user()->can('manage smm-collection'), 403);

        $collection = $this->service->collect($contentItem, $request->user());

        return response()->json(['success' => true, 'data' => $collection->load('submission', 'collectedBy:id,name')]);
    }

    /** Publishes one specific, already-collected submission — every precondition re-checked server-side, see Fix G. */
    public function publish(Request $request, Brand $brand, ContentItem $contentItem): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);
        abort_unless($request->user()->can('manage published-content'), 403);

        $data = $request->validate([
            'submission_id'     => ['required', 'integer', Rule::exists('content_item_submissions', 'id')->where('content_item_id', $contentItem->id)],
            'facebook_post_url' => ['required', 'string', 'max:2048', 'url'],
        ]);

        $submission = ContentItemSubmission::findOrFail($data['submission_id']);
        $published = $this->service->publish($contentItem, $brand, $submission, $data, $request->user());

        return response()->json(['success' => true, 'data' => $published->load('publishedBy:id,name')]);
    }

    /** Any panel role, or Manager oversight, may look at a brand's checklist. */
    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasAnyPermission([
            'view raw-content-panel', 'view designer-panel', 'view smm-panel', 'view brand-checklist-overview',
        ]), 403);
    }

    /** Raw Content Panel owns raw_content + advertising_content; Designer Panel owns poster — see Fix A. */
    private function authorizeCategory(Request $request, string $category): void
    {
        $permission = in_array($category, ContentItem::contentPanelCategories(), true)
            ? 'manage raw-content'
            : 'manage designer-content';

        abort_unless($request->user()->can($permission), 403);
    }

    /** Whoever could eventually publish or review it may send it back — SMM, Marketing's review, or Manager. */
    private function authorizeReviewer(Request $request): void
    {
        abort_unless($request->user()->hasAnyPermission([
            'manage smm-collection', 'manage publishing-review', 'view brand-checklist-overview',
        ]), 403);
    }
}
