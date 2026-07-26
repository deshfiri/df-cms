<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientActionRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING       = 'Pending';
    public const STATUS_SUBMITTED     = 'Submitted';
    public const STATUS_UNDER_REVIEW  = 'Under Review';
    public const STATUS_APPROVED      = 'Approved';
    public const STATUS_NEED_REVISION = 'Need Revision';
    public const STATUS_REJECTED      = 'Rejected';
    public const STATUS_COMPLETED     = 'Completed';

    public static array $statuses = [
        self::STATUS_PENDING, self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED, self::STATUS_NEED_REVISION, self::STATUS_REJECTED, self::STATUS_COMPLETED,
    ];

    public static array $priorities = ['Low', 'Medium', 'High', 'Urgent'];

    protected $fillable = [
        'client_id', 'stage_id', 'requested_by', 'title', 'description', 'required_attachment',
        'priority', 'due_date', 'status', 'team_feedback', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'required_attachment' => 'boolean',
            'due_date'            => 'date',
            'reviewed_at'         => 'datetime',
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

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function submissions()
    {
        return $this->hasMany(ClientActionSubmission::class)->latest();
    }

    public function latestSubmission()
    {
        return $this->hasOne(ClientActionSubmission::class)->latestOfMany();
    }

    public function scopeAwaitingClient($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_NEED_REVISION]);
    }
}
