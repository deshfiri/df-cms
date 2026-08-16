<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowItem extends Model
{
    public const STATUS_OPEN      = 'Open';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    public static array $priorities = ['Low', 'Normal', 'High', 'Urgent'];

    protected $fillable = [
        'flow_id', 'client_id', 'current_stage_id', 'assigned_to', 'title', 'description', 'priority', 'due_date', 'status', 'created_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'due_date' => 'date'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /** The client this workflow is running for, if any. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(FlowStage::class, 'current_stage_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(FlowTransition::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FlowItemAttachment::class)->latest();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FlowItemComment::class)->oldest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The user who has claimed the item at its current stage (null = unclaimed). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClaimed(): bool
    {
        return $this->assigned_to !== null;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }
}
