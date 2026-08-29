<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes, InvalidatesPerformanceBoard;

    public static array $priorities = ['Low', 'Medium', 'High', 'Urgent'];

    /**
     * 'Submitted' sits between working and done: the assignee has handed it
     * back and it is waiting on whoever assigned it to accept or return it.
     */
    public const STATUS_SUBMITTED = 'Submitted';

    public static array $statuses = ['Pending', 'In Progress', 'On Hold', 'Submitted', 'Completed', 'Cancelled', 'Overdue'];

    /** Statuses an assignee may submit from. */
    public static array $submittableStatuses = ['Pending', 'In Progress', 'On Hold'];

    /**
     * Statuses an assignee may move their own task between while working on it.
     *
     * Deliberately excludes 'Completed' and 'Cancelled': finishing is the
     * reviewer's call, and cancelling is an administrative one. Also excludes
     * 'Submitted' — that transition goes through submitForReview(), which
     * timestamps it and notifies the reviewer.
     */
    public static array $workingStatuses = ['Pending', 'In Progress', 'On Hold'];

    /**
     * Statuses where the assignee no longer owes any work, so nothing can be
     * overdue on them. 'Submitted' belongs here: the work has been handed in
     * and is waiting on a reviewer — counting it late would blame the assignee
     * for somebody else's delay, including in the performance KPI.
     */
    public static array $settledStatuses = ['Submitted', 'Completed', 'Cancelled'];
    public static array $types = ['Call', 'Meeting', 'Email', 'Follow Up', 'Visit', 'Proposal', 'Invoice', 'Support', 'Other'];

    protected $fillable = [
        'title',
        'description',
        'client_id',
        'assigned_to',
        'created_by',
        'updated_by',
        'priority',
        'status',
        'type',
        'start_date',
        'due_date',
        'completion_date',
        'submitted_at',
        'reminder_at',
        'estimated_hours',
        'actual_hours',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'completion_date' => 'date',
            'submitted_at' => 'datetime',
            'reminder_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
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

    public function revisions(): HasMany
    {
        return $this->hasMany(TaskRevision::class)->latest();
    }

    /**
     * Overdue means the due *date* has passed, not the moment it began.
     *
     * due_date is cast to a date, so it is a Carbon at midnight — isPast() on it
     * is true from 00:00, which flagged a task due today as late the instant the
     * day started. Compared against today() instead, so this agrees with
     * scopeOverdue(): a task is late the day *after* it was due.
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && $this->due_date->startOfDay()->lt(today())
            && !in_array($this->status, self::$settledStatuses, true);
    }

    /**
     * Restrict a query to the tasks this user is party to.
     *
     * A task is a piece of work between two people — whoever asked for it and
     * whoever is doing it — not a public record. Holding 'view tasks' means you
     * can use the module, not that you can read everyone else's workload.
     *
     * 'manage tasks' is the deliberate exception: managers need oversight, and
     * TaskPolicy::review() already lets them clear a review queue so work never
     * gets stuck behind someone who has left.
     *
     * This is the single definition of task visibility. Every listing must go
     * through it, so authorization happens in SQL rather than after the rows
     * have been fetched.
     */
    public function scopeVisibleTo($query, User $user)
    {
        // can(), not hasPermissionTo(): the latter throws when the permission
        // row does not exist, and this scope runs on the dashboard for every
        // user — including on an installation where it was never seeded.
        // can() also covers Super Admin through Gate::before.
        if ($user->can('manage tasks')) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('assigned_to', $user->id)
              ->orWhere('created_by', $user->id);
        });
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', today())
            ->whereNotIn('status', self::$settledStatuses);
    }
}
