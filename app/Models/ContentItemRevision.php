<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only, mirrors TaskRevision exactly. */
class ContentItemRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['content_item_id', 'requested_by', 'note', 'previous_status'];

    public function item()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
