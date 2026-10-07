<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marketing's pre-publish approval/handover of one exact
 * ContentItemSubmission — its mere existence is what makes that submission
 * eligible for SMM to collect. Append-only: nothing ever updates or deletes
 * a row here except the submission itself cascading away. See the
 * migration's own docblock for why this is a separate table rather than a
 * ContentItem status.
 */
class ContentItemSubmissionApproval extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['content_item_id', 'submission_id', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function item()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function submission()
    {
        return $this->belongsTo(ContentItemSubmission::class, 'submission_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
