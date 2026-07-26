<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN              = 'Open';
    public const STATUS_ASSIGNED          = 'Assigned';
    public const STATUS_IN_PROGRESS       = 'In Progress';
    public const STATUS_WAITING_FOR_CLIENT = 'Waiting for Client';
    public const STATUS_RESOLVED          = 'Resolved';
    public const STATUS_CLOSED            = 'Closed';

    public static array $statuses = [
        self::STATUS_OPEN, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_FOR_CLIENT, self::STATUS_RESOLVED, self::STATUS_CLOSED,
    ];

    public static array $categories = [
        'General', 'Website', 'Content', 'Marketing', 'Payment',
        'Product', 'Warehouse', 'Fulfillment', 'Technical', 'Other',
    ];

    public static array $priorities = ['Low', 'Medium', 'High', 'Urgent'];

    protected $fillable = [
        'ticket_number', 'client_id', 'created_by', 'category', 'priority', 'subject', 'message',
        'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size',
        'status', 'assigned_to', 'last_reply_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'closed_at'     => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(ClientPortalUser::class, 'created_by');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies()
    {
        return $this->hasMany(SupportTicketReply::class)->oldest();
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }
}
