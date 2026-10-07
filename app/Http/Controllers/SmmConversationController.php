<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\SmmClientConversation;
use App\Services\SmmConversationService;
use App\Services\Storage\StoredFileResponse;
use App\Support\BrandScope;
use App\Support\ReportingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SMM client conversations: an SMM user logs one with its screenshot, and
 * Marketing verifies it. Evidence is never exposed through a raw storage path.
 * It's served only through the protected preview/download, after the same
 * ownership checks that the record itself passes.
 */
class SmmConversationController extends Controller
{
    public function __construct(private readonly SmmConversationService $conversations) {}

    /**
     * SMM sees its own records. Marketing sees every record, filtered by
     * status. Both are filtered to the selected Daily/Monthly/Yearly period
     * by submitted_at — the moment the conversation itself was logged, not
     * when it was later reviewed — AND to the selected Brand (All Brands
     * when none is chosen), independently of the period.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        [$since, $until] = ReportingPeriod::fromRequest($request)->bounds();
        $brand = BrandScope::fromRequest($request, Brand::inWorkflow()->get(['id', 'name']));

        $query = SmmClientConversation::query()
            ->with(['brand:id,name', 'product:id,name', 'submitter:id,name', 'reviewer:id,name'])
            ->tap(fn ($q) => $brand->apply($q))
            ->where('submitted_at', '>=', $since)
            ->where('submitted_at', '<', $until);

        if ($user->can('manage publishing-review')) {
            $query->when($request->filled('status'), fn ($q) => $q->where('review_status', $request->string('status')));
        } elseif ($user->can('manage smm-collection')) {
            $query->where('submitted_by', $user->id);
        } else {
            abort(403);
        }

        $rows = $query->latest('submitted_at')->limit(200)->get()
            ->map(fn (SmmClientConversation $c) => $this->present($c));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage smm-collection'), 403);

        $data = $request->validate([
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'reference' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            // mimes checks the real bytes of the upload, not only its extension.
            'evidence' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ]);

        $brand = Brand::findOrFail($data['brand_id']);
        $conversation = $this->conversations->submit($brand, $data, $request->file('evidence'), $request->user());

        // 201 for a new record. A retried request returns the record it already made, with 200.
        return response()->json(
            ['success' => true, 'data' => $this->present($conversation->load(['brand:id,name', 'product:id,name', 'submitter:id,name']))],
            $conversation->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function decide(Request $request, SmmClientConversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in([SmmClientConversation::STATUS_APPROVED, SmmClientConversation::STATUS_REJECTED])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $reviewed = $this->conversations->review($conversation, $data['decision'], $request->user(), $data['note'] ?? null);

        return response()->json(['success' => true, 'data' => $this->present($reviewed->load(['brand:id,name', 'product:id,name', 'submitter:id,name', 'reviewer:id,name']))]);
    }

    /**
     * The screenshot, inline for viewing or as a download. Only the SMM user who
     * submitted the record, a Marketing reviewer, or the Manager may open it.
     */
    public function evidence(Request $request, SmmClientConversation $conversation): StreamedResponse
    {
        $user = $request->user();
        $allowed = (int) $conversation->submitted_by === (int) $user->id
            || $user->can('manage publishing-review')
            || $user->can('view brand-checklist-overview');
        abort_unless($allowed, 403);

        $mime = $conversation->evidence_mime ?: StoredFileResponse::detectMimeType($conversation->evidence_disk, $conversation->evidence_path);
        $name = 'conversation-'.$conversation->id.'.'.pathinfo($conversation->evidence_path, PATHINFO_EXTENSION);

        if ($request->boolean('download')) {
            return StoredFileResponse::download($conversation->evidence_disk, $conversation->evidence_path, $name, $mime, $conversation->evidence_size);
        }

        return StoredFileResponse::preview($conversation->evidence_disk, $conversation->evidence_path, $name, $mime, $conversation->evidence_size);
    }

    /** @return array<string, mixed> */
    private function present(SmmClientConversation $c): array
    {
        return [
            'id' => $c->id,
            'brand_id' => $c->brand_id,
            'brand' => $c->brand?->name,
            'product_id' => $c->product_id,
            'product' => $c->product?->name,
            'reference' => $c->reference,
            'note' => $c->note,
            'submitted_by' => $c->submitter?->name,
            'submitted_by_id' => $c->submitted_by,
            'submitted_at' => $c->submitted_at?->format('d M Y, h:i A'),
            'review_status' => $c->review_status,
            'reviewed_by' => $c->reviewer?->name,
            'reviewed_at' => $c->reviewed_at?->format('d M Y, h:i A'),
            'review_note' => $c->review_note,
            'evidence_url' => route('smm-conversations.evidence', $c),
            'evidence_download_url' => route('smm-conversations.evidence', ['conversation' => $c, 'download' => 1]),
        ];
    }
}
