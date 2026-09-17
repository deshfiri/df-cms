<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money given back against a payment. See RefundService for the rules.
 *
 *   requested ─┬─> under_review ─┬─> approved ──> processing ──> completed
 *              │                 └─> rejected
 *              ├─> approved / rejected (decided without a separate review step)
 *              └─> cancelled   (also from under_review or approved — never once paying out has begun)
 */
class Refund extends Model
{
    public const STATUS_REQUESTED    = 'requested';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_PROCESSING   = 'processing';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_CANCELLED    = 'cancelled';

    public const LABELS = [
        self::STATUS_REQUESTED    => 'Requested',
        self::STATUS_UNDER_REVIEW => 'Under review',
        self::STATUS_APPROVED     => 'Approved',
        self::STATUS_REJECTED     => 'Rejected',
        self::STATUS_PROCESSING   => 'Processing',
        self::STATUS_COMPLETED    => 'Completed',
        self::STATUS_CANCELLED    => 'Cancelled',
    ];

    /** The only moves a refund may make. */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED    => [self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED     => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING   => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED    => [],
        self::STATUS_REJECTED     => [],
        self::STATUS_CANCELLED    => [],
    ];

    /** Still on its way — only one of these per payment at a time. */
    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_PROCESSING];

    /**
     * Money spoken for: paid back, or on its way. Everything here counts
     * against what is still refundable, so two requests can never between them
     * return more than was paid.
     */
    public const COMMITTED_STATUSES = [self::STATUS_REQUESTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_PROCESSING, self::STATUS_COMPLETED];

    protected $fillable = [
        'refund_number', 'payment_id', 'client_id', 'invoice_id', 'amount', 'reason', 'status', 'method', 'reference',
        'requested_by', 'reviewed_by', 'review_started_at', 'decided_by', 'decided_at', 'decision_note',
        'processed_by', 'processing_at', 'completed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount'            => 'decimal:2',
            'review_started_at' => 'datetime',
            'decided_at'        => 'datetime',
            'processing_at'     => 'datetime',
            'completed_at'      => 'datetime',
            'cancelled_at'      => 'datetime',
        ];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function processedBy(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }

    public function events(): HasMany
    {
        return $this->hasMany(RefundEvent::class)->orderBy('id');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
