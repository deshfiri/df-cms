<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Document;
use App\Models\DocumentDownload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    // ── Legacy (old Document model) ──────────────────────────────
    public function upload(Client $client, UploadedFile $file, array $data): Document
    {
        return DB::transaction(function () use ($client, $file, $data) {
            $path = $file->storeAs(
                'documents/' . $client->id,
                Str::uuid() . '.' . $file->getClientOriginalExtension(),
                'local'
            );
            $doc = Document::create([
                'client_id'     => $client->id,
                'document_type' => $data['document_type'],
                'title'         => $data['title'],
                'file_path'     => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'file_size'     => $file->getSize(),
                'uploaded_by'   => Auth::id(),
            ]);
            $this->activityLog->log('Document', 'Uploaded', $client->id, null, ['type' => $data['document_type']]);
            return $doc;
        });
    }

    public function delete(Document $doc): void
    {
        DB::transaction(function () use ($doc) {
            Storage::disk('local')->delete($doc->file_path);
            $this->activityLog->log('Document', 'Deleted', $doc->client_id, $doc->toArray());
            $doc->delete();
        });
    }

    // ── New ClientDocument system ─────────────────────────────────
    public function uploadClientDocument(Client $client, UploadedFile $file, array $data): ClientDocument
    {
        return DB::transaction(function () use ($client, $file, $data) {
            $ext        = strtolower($file->getClientOriginalExtension());
            $storedName = Str::uuid() . '.' . $ext;
            $folder     = 'client-documents/' . $client->id;
            $disk       = config('filesystems.default', 'local');

            $path = $file->storeAs($folder, $storedName, $disk);

            // Find next version if replacing an existing document of this type
            $parentId = $data['parent_id'] ?? null;
            $version  = 1;
            if ($parentId) {
                $parent  = ClientDocument::findOrFail($parentId);
                $version = $parent->version + 1;
            }

            $doc = ClientDocument::create([
                'client_id'          => $client->id,
                'document_type_id'   => $data['document_type_id'],
                'uploaded_by'        => Auth::id(),
                'title'              => $data['title'],
                'description'        => $data['description'] ?? null,
                'remarks'            => $data['remarks'] ?? null,
                'original_name'      => $file->getClientOriginalName(),
                'stored_name'        => $storedName,
                'disk'               => $disk,
                'path'               => $path,
                'extension'          => $ext,
                'mime_type'          => $file->getMimeType() ?? 'application/octet-stream',
                'file_size'          => $file->getSize(),
                'version'            => $version,
                'parent_id'          => $parentId,
                'expiry_date'        => $data['expiry_date'] ?? null,
                'tags'               => isset($data['tags']) ? array_filter(array_map('trim', explode(',', $data['tags']))) : null,
            ]);

            $typeName = $doc->documentType?->name ?? 'Document';
            $this->activityLog->log(
                'Document',
                $version > 1 ? "Replaced: {$typeName} (v{$version})" : "Uploaded: {$typeName}",
                $client->id,
                null,
                ['document_id' => $doc->id, 'type' => $typeName, 'version' => $version]
            );

            return $doc;
        });
    }

    public function deleteClientDocument(ClientDocument $doc): void
    {
        DB::transaction(function () use ($doc) {
            Storage::disk($doc->disk)->delete($doc->path);
            $this->activityLog->log('Document', 'Deleted: ' . ($doc->documentType?->name ?? 'Document'), $doc->client_id, ['version' => $doc->version]);
            $doc->delete();
        });
    }

    public function logDownload(ClientDocument $doc): void
    {
        DocumentDownload::create([
            'document_id'  => $doc->id,
            'user_id'      => Auth::id(),
            'ip_address'   => Request::ip(),
            'user_agent'   => Request::userAgent(),
            'downloaded_at'=> now(),
        ]);
        $doc->increment('download_count');

        $this->activityLog->log('Document', 'Downloaded: ' . ($doc->documentType?->name ?? 'Document'), $doc->client_id, null, ['version' => $doc->version]);
    }

    public function getClientDocuments(Client $client): array
    {
        $docs = ClientDocument::with(['documentType', 'uploader:id,name'])
            ->where('client_id', $client->id)
            ->whereNull('parent_id')
            ->withCount('versions')
            ->orderByDesc('created_at')
            ->get();

        $typeGroups = $docs->groupBy('document_type_id');

        return [
            'docs'       => $docs,
            'typeGroups' => $typeGroups,
            'total'      => $docs->count(),
            'totalSize'  => $docs->sum('file_size'),
        ];
    }

    public static function allowedMimes(): array
    {
        return [
            'application/pdf',
            'image/png', 'image/jpeg', 'image/webp', 'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'application/zip',
        ];
    }
}
