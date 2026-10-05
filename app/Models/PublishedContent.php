<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Append-only except reviewed_at/reviewed_by — see Fix F. submission_id,
 * facebook_post_url, published_by and published_at are write-once; no
 * service method may change them after insert. A correction always means a
 * new row (republishing), never an edit to an existing one.
 */
class PublishedContent extends Model
{
    public const REVIEW_STATE_REVIEWED = 'reviewed';

    public const REVIEW_STATE_REVISION_REQUESTED = 'revision_requested';

    public const REVIEW_STATE_AWAITING_REVIEW = 'awaiting_review';

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

    /**
     * Whether a revision landed specifically inside THIS publication's own
     * review window — after it went live, and before whatever publication
     * (if any) superseded it for the same content item. Bounding the window
     * this way is what keeps the distinction correct across the full
     * lifecycle: a revision requested against V1 must mark V1 — and only
     * V1 — as revision-requested forever, even once V2 exists and is its
     * own, fresh "awaiting review" row with no revision against it yet.
     *
     * @param  Carbon|null  $nextPublishedAt  published_at of the next PublishedContent row for this item (by id), if any.
     * @param  Collection<int,ContentItemRevision>  $itemRevisions  Every revision for this item, any order.
     */
    public function isRevisionRequested(?Carbon $nextPublishedAt, Collection $itemRevisions): bool
    {
        return $itemRevisions->contains(function (ContentItemRevision $revision) use ($nextPublishedAt) {
            // >= on the lower bound: timestamp columns are second-precision,
            // so a revision requested moments after publishing can share
            // the same stored second — causally the publish still came
            // first, so a tie belongs to this publication, not before it.
            return $revision->created_at->gte($this->published_at)
                && ($nextPublishedAt === null || $revision->created_at->lt($nextPublishedAt));
        });
    }

    /**
     * The one authoritative review state for this publication — reviewed
     * always wins (a correction after the fact never un-reviews a row, see
     * the class docblock), otherwise revision-requested vs. awaiting-review
     * per isRevisionRequested() above. Every place that shows or acts on a
     * publication's review status reads this, never reviewed_at alone.
     */
    public function reviewState(?Carbon $nextPublishedAt, Collection $itemRevisions): string
    {
        if ($this->reviewed_at !== null) {
            return self::REVIEW_STATE_REVIEWED;
        }

        return $this->isRevisionRequested($nextPublishedAt, $itemRevisions)
            ? self::REVIEW_STATE_REVISION_REQUESTED
            : self::REVIEW_STATE_AWAITING_REVIEW;
    }

    /**
     * Annotates every given publication with its review_state. The single
     * place this is computed — Marketing's queue, the Mark-reviewed/
     * Request-revision guards and SMM's Published badge all go through
     * this, so they can never disagree. Bulk-loads whatever it needs for
     * the whole collection at once: two extra queries total, however many
     * publications or content items are involved — never one query per row.
     *
     * @param  Collection<int,PublishedContent>  $publications
     * @return Collection<int,PublishedContent>
     */
    public static function annotateReviewStates(Collection $publications): Collection
    {
        if ($publications->isEmpty()) {
            return $publications;
        }

        $itemIds = $publications->pluck('content_item_id')->unique()->values();

        $revisionsByItem = ContentItemRevision::whereIn('content_item_id', $itemIds)
            ->get()
            ->groupBy('content_item_id');

        // Every publication for each of these items — not just the ones
        // passed in — so a single historical row can still find its own
        // "next" publication even when the rest of its item's history
        // wasn't part of $publications.
        $siblingsByItem = self::whereIn('content_item_id', $itemIds)
            ->orderBy('id')
            ->get(['id', 'content_item_id', 'published_at'])
            ->groupBy('content_item_id');

        foreach ($publications as $publication) {
            $siblings = $siblingsByItem->get($publication->content_item_id, new Collection);
            $next = $siblings->first(fn (self $p) => $p->id > $publication->id);

            $state = $publication->reviewState($next?->published_at, $revisionsByItem->get($publication->content_item_id, new Collection));

            // setAttribute() alone would mark review_state dirty, so a later
            // ->update() on this same instance (e.g. the Mark-reviewed
            // guard, which annotates the very row it's about to save) would
            // try to write a column that doesn't exist. Syncing it as the
            // "original" value too keeps it purely virtual — visible in
            // toArray()/JSON, invisible to getDirty()/save().
            $publication->setAttribute('review_state', $state);
            $publication->syncOriginalAttribute('review_state');
        }

        return $publications;
    }
}
