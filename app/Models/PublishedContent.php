<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only except reviewed_at/reviewed_by — see Fix F. submission_id,
 * facebook_post_url, published_by and published_at are write-once; no
 * service method may change them after insert. A correction always means a
 * new row (republishing), never an edit to an existing one.
 */
class PublishedContent extends Model
{
    protected $fillable = [
        'content_item_id', 'submission_id', 'brand_id', 'facebook_post_url',
        'published_by', 'published_at', 'reviewed_at', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function item()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function submission()
    {
        return $this->belongsTo(ContentItemSubmission::class, 'submission_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }
}
