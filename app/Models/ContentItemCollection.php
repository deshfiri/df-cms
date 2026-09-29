<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only — SMM's explicit claim of one specific submission (see Fix B).
 * Never updated after insert.
 */
class ContentItemCollection extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['content_item_id', 'submission_id', 'collected_by', 'collected_at'];

    protected function casts(): array
    {
        return ['collected_at' => 'datetime'];
    }

    public function item()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function submission()
    {
        return $this->belongsTo(ContentItemSubmission::class, 'submission_id');
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
