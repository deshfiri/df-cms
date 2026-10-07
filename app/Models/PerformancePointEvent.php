<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;

/**
 * One awarded workflow Performance Point. Append-only: a row is never updated
 * or deleted. Its points are added to the EXISTING Performance score of
 * `user_id`, and the awarded_at timestamp decides which Daily/Monthly/Yearly
 * period it belongs to. See config/performance.php and
 * PerformanceCalculationService::finalScore().
 */
class PerformancePointEvent extends Model
{
    use InvalidatesPerformanceBoard;

    public const UPDATED_AT = null;

    public const EVENT_RAW_CONTENT_APPROVAL = 'raw_content_approval';

    public const EVENT_ADVERTISING_CONTENT_APPROVAL = 'advertising_content_approval';

    public const EVENT_POSTER_APPROVAL = 'poster_approval';

    public const EVENT_MARKETING_HANDOVER = 'marketing_handover';

    public const EVENT_MARKETING_FINAL_REVIEW = 'marketing_final_review';

    public const EVENT_SMM_PUBLISH_SUCCESS = 'smm_publish_success';

    public const EVENT_POTENTIAL_CLIENT = 'potential_client';

    /** What the source id points at. The pairing with event_type keeps the unique key exact. */
    public const SOURCE_SUBMISSION = 'content_item_submission';

    public const SOURCE_APPROVAL = 'content_item_submission_approval';

    public const SOURCE_PUBLICATION = 'published_content';

    public const SOURCE_CONVERSATION = 'smm_client_conversation';

    protected $fillable = [
        'user_id', 'event_type', 'source_type', 'source_id', 'brand_id',
        'points', 'awarded_by', 'awarded_at', 'reason',
    ];

    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function awardedBy()
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
