<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;

/**
 * One SMM client conversation, with screenshot evidence, awaiting or having had
 * Marketing's verdict. See the migration for why it holds no personal contact
 * details, and for the idempotency and historical-field rules.
 */
class SmmClientConversation extends Model
{
    use InvalidatesPerformanceBoard;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'brand_id', 'product_id', 'submitted_by', 'reference', 'note', 'evidence_disk', 'evidence_path',
        'evidence_mime', 'evidence_size', 'idempotency_key', 'review_status', 'reviewed_by', 'reviewed_at',
        'review_note', 'submitted_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->review_status === self::STATUS_PENDING;
    }
}
