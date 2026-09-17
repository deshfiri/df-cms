<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    public const STATUS_UNPAID          = 'Unpaid';
    public const STATUS_PARTIALLY_PAID  = 'Partially Paid';
    public const STATUS_PAID            = 'Paid';
    public const STATUS_OVERDUE         = 'Overdue';
    public const STATUS_REFUNDED        = 'Refunded';
    public const STATUS_NON_REFUNDABLE  = 'Non-Refundable';
    public const STATUS_CANCELLED       = 'Cancelled';

    public static array $statuses = [
        self::STATUS_UNPAID, self::STATUS_PARTIALLY_PAID, self::STATUS_PAID,
        self::STATUS_OVERDUE, self::STATUS_REFUNDED, self::STATUS_NON_REFUNDABLE, self::STATUS_CANCELLED,
    ];

    public static array $terminalStatuses = [
        self::STATUS_REFUNDED, self::STATUS_NON_REFUNDABLE, self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'client_id', 'payment_category_id', 'invoice_number', 'title', 'description', 'total_payable',
        'due_date', 'status', 'issued_by', 'issued_date', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'total_payable' => 'decimal:2',
            'due_date'      => 'date',
            'issued_date'   => 'date',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function category()
    {
        return $this->belongsTo(PaymentCategory::class, 'payment_category_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentProofSubmissions()
    {
        return $this->hasMany(PaymentProofSubmission::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Money received against this charge and kept: payments, less whatever has
     * actually been paid back (completed refunds). A refund still on its way
     * has not left the account yet, so it does not count here.
     *
     * Lists should load it with withPaidTotal() — the fallback queries are fine
     * for one invoice but run once per row otherwise.
     */
    public function getPaidAmountAttribute(): float
    {
        $received = array_key_exists('paid_total', $this->attributes)
            ? (float) $this->attributes['paid_total']
            : (float) $this->payments()->where('status', 'Paid')->sum('amount');

        $refunded = array_key_exists('refunded_total', $this->attributes)
            ? (float) $this->attributes['refunded_total']
            : (float) $this->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount');

        return round($received - $refunded, 2);
    }

    /** What has been paid back on this charge. */
    public function getRefundedAmountAttribute(): float
    {
        return round((float) (array_key_exists('refunded_total', $this->attributes)
            ? $this->attributes['refunded_total']
            : $this->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount')), 2);
    }

    public function getDueAmountAttribute(): float
    {
        return max(0, round((float) $this->total_payable - $this->paid_amount, 2));
    }

    /** Whether more money can still be taken against it. */
    public function isOpen(): bool
    {
        return !$this->isTerminal() && $this->due_amount > 0;
    }

    public function scopeWithPaidTotal(Builder $query): Builder
    {
        return $query
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'Paid')], 'amount')
            ->withSum(['refunds as refunded_total' => fn ($q) => $q->where('status', Refund::STATUS_COMPLETED)], 'amount');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::$terminalStatuses, true);
    }
}
