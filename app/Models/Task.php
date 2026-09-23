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

    /**
     * Statuses an assignee may submit from — once work has started. A task
     * still Pending has not been worked on, so there is nothing to hand in:
     * the assignee presses Start work first. See submitBlocker().
     */
    public static array $submittableStatuses = ['In Progress', 'On Hold'];

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
        'requires_attachment',
        'created_by',
        'updated_by',
        'priority',
        'status',
        'type',
        'start_date',
        'started_at',
        'due_date',
        'due_at',
        'completion_date',
        'completed_at',
        'submitted_at',
        'reminder_at',
        'estimated_hours',
        'actual_hours',
    ];

    protected function casts(): array
    {
        return [
            'requires_attachment' => 'boolean',
            'start_date' => 'date',
            'started_at' => 'datetime',
            'due_date' => 'date',
            'due_at' => 'datetime',
            'completion_date' => 'date',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reminder_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
        ];
    }

    /**
     * Keeps the lifecycle moments and their calendar days in step, whoever
     * saves the task — the form, a status button, an approval, an import.
     *
     *  - Deadline: due_at is the exact moment; due_date is its day. Setting only
     *    a day means "by the end of that day", which is what a date-only
     *    deadline has always meant here (late from the next day).
     *  - Start: the first time work moves to In Progress. Pausing and resuming
     *    do not move it; it records when work began, not when it last resumed.
     *  - Completion: stamped when the task becomes Completed, cleared if it is
     *    reopened — a reopened task is not finished.
     */
    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->isDirty('due_at')) {
                $task->due_date = $task->due_at?->toDateString();
            } elseif ($task->isDirty('due_date')) {
                $task->due_at = $task->due_date ? $task->due_date->copy()->setTime(23, 59, 59) : null;
            }

            if (!$task->isDirty('status')) {
                return;
            }

            if ($task->status === 'In Progress' && $task->started_at === null) {
                $task->started_at = now();
            }

            if ($task->status === 'Completed') {
                $task->completed_at ??= now();
                $task->completion_date ??= $task->completed_at->toDateString();
            } elseif ($task->getOriginal('status') === 'Completed') {
                $task->completed_at    = null;
                $task->completion_date = null;
            }
        });
    }

    /**
     * Why this task can't be submitted right now, or null when it can.
     *
     * The one rule, shared by the policy (who sees the button), the service
     * (checked again under a row lock) and the page (what the disabled button
     * says). In Progress counts as started even without a recorded start —
     * older tasks were moved there before starts were timestamped.
     */
    public function submitBlocker(): ?string
    {
        return match (true) {
            $this->status === self::STATUS_SUBMITTED => 'This task has already been submitted and is waiting for review.',
            in_array($this->status, ['Completed', 'Cancelled'], true) => "This task is {$this->status} and can no longer be submitted.",
            $this->status === 'Pending',
            $this->status === 'On Hold' && $this->started_at === null => 'Start work on this task before submitting it.',
            !in_array($this->status, self::$submittableStatuses, true) => "This task is {$this->status} and can't be submitted.",
            default => null,
        };
    }

    /** True when the deadline carries a time, not just a day. */
    public function dueHasTime(): bool
    {
        return $this->due_at !== null && $this->due_at->format('H:i:s') !== '23:59:59';
    }

    /**
     * Everything a live counter needs, computed on the server.
     *
     * `server_now` lets the browser correct for its own clock being wrong, so a
     * counter reads the same on every machine and after every refresh. States:
     * not_started, running, paused, overdue, submitted, completed, cancelled.
     *
     * @return array<string,mixed>
     */
    public function timer(): array
    {
        $state = match (true) {
            $this->status === 'Completed'           => 'completed',
            $this->status === 'Cancelled'           => 'cancelled',
            $this->status === self::STATUS_SUBMITTED => 'submitted',
            $this->is_overdue                        => 'overdue',
            $this->status === 'In Progress'          => 'running',
            $this->started_at !== null               => 'paused',
            default                                  => 'not_started',
        };

        // Work stops being "in hand" once it is handed in or finished.
        $until = $this->completed_at ?? $this->submitted_at;

        return [
            'state'             => $state,
            'server_now'        => now()->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
            'started_at'        => $this->started_at?->toIso8601String(),
            'due_at'            => $this->due_at?->toIso8601String(),
            'due_has_time'      => $this->dueHasTime(),
            'submitted_at'      => $this->submitted_at?->toIso8601String(),
            'completed_at'      => $this->completed_at?->toIso8601String(),
            'estimated_seconds' => $this->estimated_hours !== null ? (int) round((float) $this->estimated_hours * 3600) : null,
            // Wall-clock time from first start to hand-in (or now, if still open).
            'elapsed_seconds'   => $this->started_at ? (int) $this->started_at->diffInSeconds($until ?? now()) : null,
        ];
    }

    /** A task may be for more than one client at once, or none at all (internal work). */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_task');
    }

    /** A task may now be held by more than one person at once, sharing full ownership of it. */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_user');
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

    /** Links and notes shared beside the files. */
    public function notes(): HasMany
    {
        return $this->hasMany(TaskNote::class)->latest();
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

    /** Who was involved and how much of the work is theirs — see TaskInvolvementService. */
    public function involvements(): HasMany
    {
        return $this->hasMany(TaskInvolvement::class);
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
        if (in_array($this->status, self::$settledStatuses, true)) {
            return false;
        }

        // The exact deadline when there is one; a date-only deadline's due_at
        // is the end of that day, so "due today" still is not late.
        if ($this->due_at) {
            return $this->due_at->lt(now());
        }

        return $this->due_date && $this->due_date->startOfDay()->lt(today());
    }

    /**
     * Restrict a query to the tasks this user is party to.
     *
     * A task is a piece of work between two people — whoever asked for it and
     * whoever is doing it — not a public record. Holding 'view tasks' means you
     * can use the module, not that you can read everyone else's workload.
     *
     * 'manage all tasks' is the deliberate exception: oversight — admins and
     * whoever is granted it see everything, and TaskPolicy::review() lets them
     * clear a review queue so work never gets stuck behind someone who has left.
     * Plain 'manage tasks' (handing out work) does not open anyone else's tasks.
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
        if ($user->can('manage all tasks')) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->whereHas('assignees', fn ($aq) => $aq->where('users.id', $user->id))
              ->orWhere('created_by', $user->id);
        });
    }

    /**
     * What is waiting on this person, for the sidebar badge.
     *
     *   open       assigned to them and not yet handed in, finished or cancelled
     *   overdue    of those, past the deadline (same rule as scopeOverdue)
     *   to_review  handed in to them by someone they assigned it to
     *   total      open + to_review — everything they have to act on
     *
     * A handful of small counts, not one raw query — assignment now lives in
     * a pivot table, so this can no longer be a single CASE-based SELECT.
     *
     * @return array{open:int, overdue:int, to_review:int, total:int}
     */
    public static function pendingCountsFor(User $user): array
    {
        $mine = fn () => static::whereHas('assignees', fn ($q) => $q->where('users.id', $user->id));

        $open = $mine()->whereNotIn('status', self::$settledStatuses)->count();

        $overdue = $mine()->whereNotIn('status', self::$settledStatuses)
            ->where(fn ($q) => $q
                ->where('due_at', '<', now())
                ->orWhere(fn ($legacy) => $legacy->whereNull('due_at')->where('due_date', '<', today())))
            ->count();

        $toReview = static::where('created_by', $user->id)
            ->whereDoesntHave('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->where('status', self::STATUS_SUBMITTED)
            ->count();

        return [
            'open'      => $open,
            'overdue'   => $overdue,
            'to_review' => $toReview,
            'total'     => $open + $toReview,
        ];
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /** Same rule as is_overdue, in SQL. */
    public function scopeOverdue($query)
    {
        return $query->whereNotIn('status', self::$settledStatuses)
            ->where(fn ($q) => $q
                ->where('due_at', '<', now())
                ->orWhere(fn ($legacy) => $legacy->whereNull('due_at')->whereDate('due_date', '<', today())));
    }
}
