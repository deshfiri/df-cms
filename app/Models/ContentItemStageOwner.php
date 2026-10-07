<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One owner of one workflow stage of one exact version. Append-only: a row is
 * released (released_at set, active_ref cleared) rather than deleted or
 * updated. See the migration's docblock for why and how the unique active_ref
 * makes "one live owner per stage" a database guarantee.
 */
class ContentItemStageOwner extends Model
{
    public const UPDATED_AT = null;

    public const STAGE_MAKER = 'maker';

    public const STAGE_PRE_PUBLISH = 'pre_publish';

    public const STAGE_PUBLISH = 'publish';

    public const STAGE_FINAL_REVIEW = 'final_review';

    /** Made the item and therefore owns its first version. Derived, not stored, for legacy items. */
    public const SOURCE_CREATED = 'created';

    public const SOURCE_CLAIMED = 'claimed';

    public const SOURCE_ASSIGNED = 'assigned';

    public const SOURCE_REASSIGNED = 'reassigned';

    protected $fillable = [
        'content_item_id', 'stage', 'stage_ref', 'active_ref', 'submission_id', 'publication_id',
        'revision_id', 'user_id', 'source', 'assigned_by', 'acquired_at', 'released_at', 'released_by', 'release_reason',
    ];

    protected function casts(): array
    {
        return ['acquired_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function item()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }
}
