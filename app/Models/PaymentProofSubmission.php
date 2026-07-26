<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProofSubmission extends Model
{
    public const STATUS_PENDING  = 'Pending';
    public const STATUS_VERIFIED = 'Verified';
    public const STATUS_REJECTED = 'Rejected';

    public static array $statuses = [self::STATUS_PENDING, self::STATUS_VERIFIED, self::STATUS_REJECTED];

    protected $fillable = [
        'client_id', 'invoice_id', 'submitted_by', 'amount_claimed', 'payment_method',
        'transaction_reference', 'payment_date', 'original_name', 'stored_name', 'disk', 'path',
        'mime_type', 'file_size', 'status', 'verified_by', 'verified_at', 'verification_note',
        'resulting_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_claimed' => 'decimal:2',
            'payment_date'   => 'date',
            'verified_at'    => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(ClientPortalUser::class, 'submitted_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function resultingPayment()
    {
        return $this->belongsTo(Payment::class, 'resulting_payment_id');
    }
}
