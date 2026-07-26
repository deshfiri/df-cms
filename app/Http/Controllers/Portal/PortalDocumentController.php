<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use App\Policies\Portal\ClientDocumentPolicy;
use App\Services\DocumentService;
use App\Services\PortalActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalDocumentController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly ClientDocumentPolicy $policy,
        private readonly PortalActivityLogService $activityLog,
        private readonly DocumentService $documentService,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;

        $documents = ClientDocument::with('documentType')
            ->where('client_id', $client->id)
            ->clientVisible()
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->get();

        $submittableTypes = DocumentType::where('is_client_submittable', true)->active()->get();

        return view('portal.documents.index', compact('documents', 'submittableTypes'));
    }

    public function store(Request $request)
    {
        $documentType = DocumentType::findOrFail($request->input('document_type_id'));
        abort_unless($this->policy->upload($this->portalUser(), $documentType), 403);

        $data = $request->validate([
            'document_type_id' => ['required', 'exists:document_types,id'],
            'title'            => ['required', 'string', 'max:200'],
            'file'             => ['required', 'file', 'max:20480', 'mimes:' . implode(',', ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xlsx', 'xls', 'csv', 'zip'])],
        ]);

        $client = $this->portalUser()->client;

        $document = $this->documentService->uploadClientDocument($client, $request->file('file'), [
            'document_type_id' => $data['document_type_id'],
            'title'            => $data['title'],
        ]);

        $document->update([
            'is_client_submitted'         => true,
            'submitted_by_portal_user_id' => $this->portalUser()->id,
            'client_review_status'        => 'Pending Review',
            'is_client_visible'           => true,
            'uploaded_by'                 => null,
        ]);

        $this->activityLog->log($this->portalUser(), 'Document', 'Uploaded', ClientDocument::class, $document->id);

        return redirect()->route('portal.documents.index')->with('success', 'Document submitted for review.');
    }

    public function download(ClientDocument $document)
    {
        abort_unless($this->policy->view($this->portalUser(), $document), 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        $document->increment('download_count');
        $this->activityLog->log($this->portalUser(), 'Document', 'Downloaded', ClientDocument::class, $document->id);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function preview(ClientDocument $document)
    {
        abort_unless($this->policy->view($this->portalUser(), $document), 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        $this->activityLog->log($this->portalUser(), 'Document', 'Viewed', ClientDocument::class, $document->id);

        return response()->file(Storage::disk($document->disk)->path($document->path), [
            'Content-Type' => $document->mime_type,
        ]);
    }
}
