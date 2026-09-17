<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's part in one task — role, work points and work share.
 *
 * Derived, never edited: TaskInvolvementService rebuilds these rows from the
 * task's activity log, which is where the evidence lives.
 */
class TaskInvolvement extends Model
{
    protected $fillable = [
        'task_id', 'user_id', 'role', 'points', 'review_points', 'share', 'events_count',
        'assigned_at', 'released_at', 'first_activity_at', 'last_activity_at', 'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'points'            => 'float',
            'review_points'     => 'float',
            'share'             => 'float',
            'events_count'      => 'integer',
            'assigned_at'       => 'datetime',
            'released_at'       => 'datetime',
            'first_activity_at' => 'datetime',
            'last_activity_at'  => 'datetime',
            'breakdown'         => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
