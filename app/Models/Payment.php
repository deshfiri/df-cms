<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'client_id', 'amount', 'payment_date', 'payment_method',
        'transaction_number', 'status', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount'       => 'decimal:2',
        ];
    }

    public static array $statuses   = ['Paid', 'Partial', 'Unpaid'];
    public static array $methods    = ['Bank Transfer', 'Cash', 'bKash', 'Nagad', 'Cheque', 'Other'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
