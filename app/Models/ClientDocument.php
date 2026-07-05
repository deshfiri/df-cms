<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ClientDocument extends Model
{
    use SoftDeletes;

    protected $table = 'client_documents';

    protected $fillable = [
        'client_id', 'document_type_id', 'uploaded_by',
        'title', 'description', 'remarks',
        'original_name', 'stored_name', 'disk', 'path', 'extension', 'mime_type', 'file_size',
        'version', 'parent_id', 'expiry_date', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date'    => 'date',
            'tags'           => 'array',
            'file_size'      => 'integer',
            'version'        => 'integer',
            'download_count' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ClientDocument::class, 'parent_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ClientDocument::class, 'parent_id')->orderByDesc('version');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DocumentDownload::class, 'document_id');
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 0)    . ' KB';
        return $bytes . ' B';
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getIconAttribute(): string
    {
        return match(true) {
            $this->is_image          => 'bi-image',
            $this->is_pdf            => 'bi-file-earmark-pdf',
            str_contains($this->mime_type, 'word')  => 'bi-file-earmark-word',
            str_contains($this->mime_type, 'excel') => 'bi-file-earmark-excel',
            str_contains($this->mime_type, 'zip')   => 'bi-file-zip',
            default                  => 'bi-file-earmark',
        };
    }

    public function getStorageUrlAttribute(): string
    {
        return route('clients.documents.preview', [$this->client_id, $this->id]);
    }
}
