<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ClientStageProgress extends Model
{
    public const STATUS_PENDING       = 'Pending';
    public const STATUS_IN_PROGRESS   = 'In Progress';
    public const STATUS_SUBMITTED     = 'Submitted';
    public const STATUS_NEED_REVISION = 'Need Revision';
    public const STATUS_APPROVED      = 'Approved';
    public const STATUS_REJECTED      = 'Rejected';

    protected $fillable = [
        'client_id', 'stage_id', 'status', 'is_completed',
        'submitted_by', 'submitted_at',
        'completed_at', 'completed_by',
        'remarks', 'rejection_reason',
    ];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('dash.workflow_completion')
            + Cache::forget('dash.delayed_count')
            + Cache::forget('dash.pipeline_segments');

        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
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

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
