<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientApprovalRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING             = 'Pending';
    public const STATUS_APPROVED             = 'Approved';
    public const STATUS_REVISION_REQUESTED   = 'Revision Requested';
    public const STATUS_REJECTED             = 'Rejected';
    public const STATUS_EXPIRED              = 'Expired';

    public static array $statuses = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REVISION_REQUESTED,
        self::STATUS_REJECTED, self::STATUS_EXPIRED,
    ];

    public static array $types = [
        'Logo', 'Brand', 'Product', 'Content', 'Video', 'Website',
        'Supplier', 'Quotation', 'Agreement', 'Campaign',
    ];

    protected $fillable = [
        'client_id', 'stage_id', 'approval_type', 'title', 'description', 'version',
        'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size',
        'external_preview_url', 'requested_by', 'deadline', 'allow_reject', 'status',
    ];

    protected function casts(): array
    {
        return [
            'allow_reject' => 'boolean',
            'deadline'     => 'date',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'stage_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function responses()
    {
        return $this->hasMany(ClientApprovalResponse::class)->latest();
    }

    public function latestResponse()
    {
        return $this->hasOne(ClientApprovalResponse::class)->latestOfMany();
    }

    public function scopeAwaitingClient($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_REVISION_REQUESTED]);
    }
}
