<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientCorrectionRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING             = 'Pending';
    public const STATUS_APPROVED             = 'Approved';
    public const STATUS_REJECTED             = 'Rejected';
    public const STATUS_NEED_MORE_INFO       = 'Need More Information';

    public static array $statuses = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_NEED_MORE_INFO,
    ];

    public static array $categories = [
        'Personal', 'Company', 'Brand', 'Contact', 'Billing', 'Delivery', 'Business', 'Product',
    ];

    protected $fillable = [
        'client_id', 'submitted_by', 'category', 'field_label', 'current_value', 'requested_value',
        'reason', 'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size',
        'status', 'reviewed_by', 'reviewed_at', 'review_note', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'applied_at'  => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(ClientPortalUser::class, 'submitted_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
