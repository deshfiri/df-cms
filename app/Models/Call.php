<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 1:1 audio call. Reverb carries only signalling; the audio itself travels
 * peer-to-peer over WebRTC and is never persisted or proxied through Laravel.
 * SDP and ICE payloads are deliberately relayed and discarded, never stored.
 */
class Call extends Model
{
    /** Waiting for the callee to pick up. */
    public const STATUS_RINGING = 'ringing';
    /** Callee accepted; media negotiation and conversation happen here. */
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    /** Rang out without an answer. */
    public const STATUS_MISSED = 'missed';
    /** Callee was already on another call. */
    public const STATUS_BUSY = 'busy';
    /** Completed normally — the only status that carries a duration. */
    public const STATUS_ENDED = 'ended';
    /** Negotiation or transport failed (ICE never connected, etc.). */
    public const STATUS_FAILED = 'failed';
    /** Caller hung up before the callee answered. */
    public const STATUS_CANCELLED = 'cancelled';

    /** A call in one of these states occupies both participants. */
    public const ACTIVE_STATUSES = [self::STATUS_RINGING, self::STATUS_ACCEPTED];

    public static array $statuses = [
        self::STATUS_RINGING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_MISSED,
        self::STATUS_BUSY,
        self::STATUS_ENDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'uuid', 'conversation_id', 'caller_id', 'callee_id', 'status',
        'started_at', 'answered_at', 'ended_at', 'duration_seconds',
        'ended_by', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'answered_at' => 'datetime',
            'ended_at'    => 'datetime',
        ];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->caller_id === $userId || $this->callee_id === $userId;
    }

    /** The participant who isn't $userId — i.e. who a signal should be relayed to. */
    public function otherParticipantId(int $userId): int
    {
        return $this->caller_id === $userId ? $this->callee_id : $this->caller_id;
    }

    /** Calls currently occupying this user, in either direction. */
    public function scopeActiveFor(Builder $query, int $userId): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES)
            ->where(fn ($q) => $q->where('caller_id', $userId)->orWhere('callee_id', $userId));
    }

    /** mm:ss for UI and the chat system message. */
    public function formattedDuration(): string
    {
        $seconds = (int) ($this->duration_seconds ?? 0);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
