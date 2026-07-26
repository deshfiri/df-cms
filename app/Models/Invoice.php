<?php

namespace App\Models;

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
        'client_id', 'invoice_number', 'title', 'description', 'total_payable',
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

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentProofSubmissions()
    {
        return $this->hasMany(PaymentProofSubmission::class);
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) $this->payments()->where('status', 'Paid')->sum('amount');
    }

    public function getDueAmountAttribute(): float
    {
        return max(0, (float) $this->total_payable - $this->paid_amount);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::$terminalStatuses, true);
    }
}
