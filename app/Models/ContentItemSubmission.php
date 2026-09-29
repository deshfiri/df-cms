<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: every submission or resubmission is a new row, never an
 * update to a prior one — mirrors TaskAttachment. updated_at still exists
 * because file_path/disk may be repointed later by the provider-upload
 * queue (see UploadStaging), not because the submission itself changes.
 */
class ContentItemSubmission extends Model
{
    protected $fillable = ['content_item_id', 'file_path', 'disk', 'link_url', 'submitted_by'];

    public function item()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function collection()
    {
        return $this->hasOne(ContentItemCollection::class, 'submission_id')->latestOfMany();
    }

    public function publishedContents()
    {
        return $this->hasMany(PublishedContent::class, 'submission_id');
    }

    /** Whether a newer submission exists for the same item — see Fix G. */
    public function isSuperseded(): bool
    {
        return $this->item->submissions()->where('id', '>', $this->id)->exists();
    }
}
