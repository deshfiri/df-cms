<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One checklist line item. Status here is always "what's true right now" —
 * every submission, collection, publish and review lives in its own
 * append-only table keyed to this item (and, past the first submission, to
 * a specific submission_id) so history is never lost to a later revision.
 * See the SRS integration plan's Fix C for the full lifecycle.
 */
class ContentItem extends Model
{
    use SoftDeletes;

    public const CATEGORY_RAW_CONTENT = 'raw_content';

    public const CATEGORY_POSTER = 'poster';

    public const CATEGORY_ADVERTISING_CONTENT = 'advertising_content';

    public static array $categories = [
        self::CATEGORY_RAW_CONTENT, self::CATEGORY_POSTER, self::CATEGORY_ADVERTISING_CONTENT,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_NEEDS_REVISION = 'needs_revision';

    public static array $statuses = [
        self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_AVAILABLE,
        self::STATUS_COLLECTED, self::STATUS_PUBLISHED, self::STATUS_NEEDS_REVISION,
    ];

    protected $fillable = ['checklist_id', 'brand_id', 'product_id', 'category', 'status', 'title', 'created_by'];

    public function checklist()
    {
        return $this->belongsTo(BrandChecklist::class, 'checklist_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every submission ever made against this item, oldest first — a full, never-pruned history. */
    public function submissions()
    {
        return $this->hasMany(ContentItemSubmission::class);
    }

    public function latestSubmission(): ?ContentItemSubmission
    {
        return $this->submissions()->latest('id')->first();
    }

    /**
     * Same row as latestSubmission(), as an eager-loadable relation instead
     * of a query method — for list endpoints that would otherwise N+1 by
     * calling latestSubmission() once per row (see PanelController).
     */
    public function latestSubmissionRelation()
    {
        return $this->hasOne(ContentItemSubmission::class)->latestOfMany();
    }

    public function revisions()
    {
        return $this->hasMany(ContentItemRevision::class);
    }

    public function collections()
    {
        return $this->hasMany(ContentItemCollection::class);
    }

    public function latestCollection(): ?ContentItemCollection
    {
        return $this->collections()->latest('id')->first();
    }

    /** Same row as latestCollection(), as an eager-loadable relation — see latestSubmissionRelation(). */
    public function latestCollectionRelation()
    {
        return $this->hasOne(ContentItemCollection::class)->latestOfMany();
    }

    public function publishedContents()
    {
        return $this->hasMany(PublishedContent::class);
    }

    /** Marketing's pre-publish approvals across every version ever submitted — see latestSubmission()->approval for the one that matters right now. */
    public function submissionApprovals()
    {
        return $this->hasMany(ContentItemSubmissionApproval::class);
    }

    public function latestPublished(): ?PublishedContent
    {
        return $this->publishedContents()->latest('id')->first();
    }

    /** Categories the Raw Content Panel (Content role) owns — see Fix A. */
    public static function contentPanelCategories(): array
    {
        return [self::CATEGORY_RAW_CONTENT, self::CATEGORY_ADVERTISING_CONTENT];
    }
}
