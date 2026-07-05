<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $service,
    ) {}

    // ── New system: ClientDocument ─────────────────────────────────

    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);
        $data = $this->service->getClientDocuments($client);

        return response()->json([
            'docs'      => $data['docs']->map(fn (ClientDocument $d) => $this->docResource($d)),
            'total'     => $data['total'],
            'totalSize' => $this->humanSize($data['totalSize']),
        ]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);
        $request->validate([
            'file'             => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx,xlsx,xls,csv,zip'],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'title'            => ['required', 'string', 'max:200'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'remarks'          => ['nullable', 'string', 'max:500'],
            'expiry_date'      => ['nullable', 'date'],
            'parent_id'        => ['nullable', 'exists:client_documents,id'],
            'tags'             => ['nullable', 'string'],
        ]);

        $doc = $this->service->uploadClientDocument($client, $request->file('file'), $request->except('file'));
        $doc->load(['documentType', 'uploader:id,name']);

        return response()->json(['success' => true, 'document' => $this->docResource($doc)], 201);
    }

    public function download(Client $client, ClientDocument $document): StreamedResponse
    {
        $this->authorize('view', $client);
        abort_if($document->client_id !== $client->id, 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        $this->service->logDownload($document);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function preview(Client $client, ClientDocument $document)
    {
        $this->authorize('view', $client);
        abort_if($document->client_id !== $client->id, 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return response()->file(
            Storage::disk($document->disk)->path($document->path),
            ['Content-Type' => $document->mime_type]
        );
    }

    public function versions(Client $client, ClientDocument $document): JsonResponse
    {
        $this->authorize('view', $client);
        abort_if($document->client_id !== $client->id, 403);

        // Get the root document (parent_id === null)
        $root = $document->parent_id ? ClientDocument::find($document->parent_id) : $document;

        $versions = ClientDocument::withTrashed()
            ->where(fn ($q) => $q->where('id', $root->id)->orWhere('parent_id', $root->id))
            ->with('uploader:id,name')
            ->orderByDesc('version')
            ->get()
            ->map(fn (ClientDocument $d) => $this->docResource($d));

        return response()->json($versions);
    }

    public function destroy(Client $client, ClientDocument $document): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($document->client_id !== $client->id, 403);

        $this->service->deleteClientDocument($document);

        return response()->json(['success' => true]);
    }

    // ── Shared resource formatter ──────────────────────────────────

    private function docResource(ClientDocument $d): array
    {
        return [
            'id'            => $d->id,
            'title'         => $d->title,
            'type_name'     => $d->documentType?->name,
            'type_icon'     => $d->documentType?->icon ?? 'bi-file-earmark',
            'original_name' => $d->original_name,
            'extension'     => strtoupper($d->extension),
            'size'          => $this->humanSize($d->file_size),
            'mime'          => $d->mime_type,
            'icon'          => $d->icon,
            'is_image'      => $d->is_image,
            'is_pdf'        => $d->is_pdf,
            'version'       => $d->version,
            'versions_count'=> $d->versions_count ?? 0,
            'uploader'      => $d->uploader?->name ?? 'Unknown',
            'uploaded_at'   => $d->created_at?->format('d M Y, h:i A'),
            'uploaded_ago'  => $d->created_at?->diffForHumans(),
            'expiry_date'   => $d->expiry_date?->format('d M Y'),
            'description'   => $d->description,
            'remarks'       => $d->remarks,
            'tags'          => $d->tags ?? [],
            'download_count'=> $d->download_count,
            'preview_url'   => route('clients.documents.preview',  [$client->id ?? $d->client_id, $d->id]),
            'download_url'  => route('clients.documents.download', [$client->id ?? $d->client_id, $d->id]),
            'deleted_at'    => $d->deleted_at?->format('d M Y'),
        ];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024)       . ' KB';
        return $bytes . ' B';
    }
}
