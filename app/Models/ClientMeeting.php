<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMeeting extends Model
{
    protected $fillable = [
        'client_id', 'created_by', 'assigned_to', 'title', 'agenda', 'type',
        'location', 'meeting_link', 'google_event_id', 'google_meet_url',
        'scheduled_at', 'duration_minutes',
        'status', 'notes', 'completed_at', 'completed_by',
        'reminder_24h_sent_at', 'reminder_1h_sent_at', 'reminder_15m_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at'          => 'datetime',
            'completed_at'          => 'datetime',
            'reminder_24h_sent_at'  => 'datetime',
            'reminder_1h_sent_at'   => 'datetime',
            'reminder_15m_sent_at'  => 'datetime',
        ];
    }

    public static array $types = ['in_person', 'phone', 'video', 'online'];

    public static array $statuses = ['Pending', 'Scheduled', 'Completed', 'Cancelled', 'No Show', 'Rescheduled'];

    public static array $typeLabels = [
        'in_person' => 'In Person',
        'phone'     => 'Phone Call',
        'video'     => 'Video Call',
        'online'    => 'Online',
    ];

    public static array $typeIcons = [
        'in_person' => 'bi-person-fill',
        'phone'     => 'bi-telephone-fill',
        'video'     => 'bi-camera-video-fill',
        'online'    => 'bi-globe',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeUpcoming($q)
    {
        return $q->where('scheduled_at', '>=', now())->whereIn('status', ['Pending', 'Scheduled']);
    }

    public function scopeToday($q)
    {
        return $q->whereDate('scheduled_at', today());
    }

    public function scopeOverdue($q)
    {
        return $q->where('scheduled_at', '<', now())->whereIn('status', ['Pending', 'Scheduled']);
    }

    public function scopeScheduled($q)
    {
        return $q->whereIn('status', ['Pending', 'Scheduled']);
    }

    // ── Computed ─────────────────────────────────────────────────────────────

    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, ['Pending', 'Scheduled'], true) && $this->scheduled_at->isPast();
    }

    /**
     * The link to actually join with — prefers the auto-generated Google Meet
     * URL, falling back to a manually entered link if Google isn't configured.
     */
    public function getJoinUrlAttribute(): ?string
    {
        return $this->google_meet_url ?: $this->meeting_link;
    }

    public function getDurationHumanAttribute(): string
    {
        $mins = $this->duration_minutes;
        if ($mins < 60) {
            return $mins . ' min';
        }
        $h = (int) floor($mins / 60);
        $m = $mins % 60;

        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }

    public function getTypeLabelAttribute(): string
    {
        return self::$typeLabels[$this->type] ?? ucfirst($this->type);
    }

    public function getTypeIconAttribute(): string
    {
        return self::$typeIcons[$this->type] ?? 'bi-calendar-event';
    }
}
