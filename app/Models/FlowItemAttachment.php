<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowItemAttachment extends Model
{
    public const KIND_FILE = 'file';
    public const KIND_LINK = 'link';
    public const KIND_NOTE = 'note';

    protected $fillable = [
        'flow_item_id', 'kind', 'title',
        'original_name', 'file_path', 'disk', 'mime_type', 'file_size',
        'url', 'body', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FlowItem::class, 'flow_item_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isFile(): bool
    {
        return $this->kind === self::KIND_FILE;
    }

    public function isLink(): bool
    {
        return $this->kind === self::KIND_LINK;
    }

    public function isNote(): bool
    {
        return $this->kind === self::KIND_NOTE;
    }

    public function isImage(): bool
    {
        return $this->isFile() && str_starts_with((string) $this->mime_type, 'image/');
    }
}
