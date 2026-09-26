<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING  = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    public static array $statuses = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'subject', 'message', 'client_id', 'requested_by',
        'status', 'response_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Who this request was sent to — the only people (besides the requester) who may see or respond to it. */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_request_recipients')
            ->withPivot(['status', 'note', 'responded_at']);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Why $user personally can't respond right now — null means they can.
     * Two independent facts, checked in order: the request as a whole may
     * already be decisively settled (anyone rejected, or everyone approved),
     * or — while it's still open — this one person may have already sent
     * their own answer and be waiting on the others. $recipients must be
     * eager-loaded with the pivot columns (see recipients()).
     */
    public function respondBlockerFor(User $user): ?string
    {
        if ($this->status !== self::STATUS_PENDING) {
            return $this->status === self::STATUS_REJECTED
                ? 'This request has already been rejected.'
                : 'This request has already been approved by everyone.';
        }

        $mine = $this->recipients->firstWhere('id', $user->id);

        if ($mine && $mine->pivot->status !== self::STATUS_PENDING) {
            return 'You already responded to this request — waiting on the other recipient(s).';
        }

        return null;
    }
}
