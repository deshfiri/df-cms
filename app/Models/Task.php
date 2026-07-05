<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    public static array $priorities = ['Low', 'Medium', 'High', 'Urgent'];
    public static array $statuses   = ['Pending', 'In Progress', 'On Hold', 'Completed', 'Cancelled', 'Overdue'];
    public static array $types      = ['Call', 'Meeting', 'Email', 'Follow Up', 'Visit', 'Proposal', 'Invoice', 'Support', 'Other'];

    protected $fillable = [
        'title', 'description', 'client_id', 'assigned_to', 'created_by', 'updated_by',
        'priority', 'status', 'type',
        'start_date', 'due_date', 'completion_date', 'reminder_at',
        'estimated_hours', 'actual_hours',
    ];

    protected function casts(): array
    {
        return [
            'start_date'       => 'date',
            'due_date'         => 'date',
            'completion_date'  => 'date',
            'reminder_at'      => 'datetime',
            'estimated_hours'  => 'decimal:2',
            'actual_hours'     => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->latest();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_task');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && !in_array($this->status, ['Completed', 'Cancelled'], true);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', today())
            ->whereNotIn('status', ['Completed', 'Cancelled']);
    }
}
