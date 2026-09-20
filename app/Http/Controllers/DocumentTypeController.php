<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Document Types: what a client's documents can be filed under.
 *
 * The list drives the type picker on a client's Documents tab and, for the
 * types marked client-submittable, the upload form in the client portal.
 *
 * Held by 'manage document types' — Super Admin and Manager out of the box.
 */
class DocumentTypeController extends Controller
{
    public const PERMISSION = 'manage document types';

    /** Bootstrap icon names offered in the picker; any other is kept as typed. */
    public const ICONS = [
        'bi-file-earmark', 'bi-file-earmark-text', 'bi-file-earmark-check', 'bi-file-earmark-pdf',
        'bi-file-earmark-medical', 'bi-receipt', 'bi-cash-stack', 'bi-credit-card', 'bi-bank',
        'bi-badge', 'bi-palette', 'bi-image', 'bi-camera-video', 'bi-building', 'bi-person-badge',
        'bi-journal-text', 'bi-truck', 'bi-display', 'bi-shield-check', 'bi-pencil-square',
    ];

    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeManage($request);

        $types = DocumentType::ordered()->withCount('documents')->get();

        return view('settings.document-types', [
            'types' => $types,
            'icons' => self::ICONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request);
        $data['sort_order'] ??= (int) DocumentType::max('sort_order') + 1;

        $type = DocumentType::create($data + ['is_active' => true]);

        $this->activityLog->log('Document Type', 'Created', null, null, $this->describe($type));

        return response()->json(['success' => true, 'data' => $type]);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->authorizeManage($request);

        $before = $this->describe($documentType);
        // The slug is left alone on rename: it was set when the type was created
        // and nothing gains from churning it.
        $documentType->update($this->validated($request, $documentType));

        $this->activityLog->log('Document Type', 'Updated', null, $before, $this->describe($documentType->fresh()));

        return response()->json(['success' => true, 'data' => $documentType->fresh()]);
    }

    /** Only a type nothing is filed under can go; the rest are deactivated. */
    public function destroy(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->authorizeManage($request);

        if ($documentType->isInUse()) {
            return response()->json([
                'message' => "Documents are already filed under \"{$documentType->name}\". Switch it off instead — "
                    . 'it disappears from the upload forms and those documents keep their type.',
            ], 422);
        }

        $name = $documentType->name;
        $this->activityLog->log('Document Type', 'Deleted', null, $this->describe($documentType), null);
        $documentType->delete();

        return response()->json(['success' => true, 'message' => "\"{$name}\" removed."]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request, ?DocumentType $type = null): array
    {
        $required = $type ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [
                $required, 'string', 'max:100',
                Rule::unique('document_types', 'name')->ignore($type?->id),
            ],
            'description'           => ['sometimes', 'nullable', 'string', 'max:255'],
            // Free text so a new Bootstrap icon works without a code change,
            // but shaped like one so it cannot carry markup into the page.
            'icon'                  => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^bi-[a-z0-9-]+$/'],
            'is_required'           => ['sometimes', 'boolean'],
            'is_client_submittable' => ['sometimes', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
            'sort_order'            => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65000'],
        ], [
            'icon.regex' => 'An icon looks like "bi-file-earmark" — pick one from the list.',
            'name.unique' => 'There is already a document type with that name.',
        ]);
    }

    /** @return array<string,mixed> */
    private function describe(DocumentType $type): array
    {
        return $type->only(['id', 'name', 'icon', 'is_required', 'is_client_submittable', 'is_active', 'sort_order']);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);
    }
}
