<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'read_at',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'attachment_duration', 'deleted_at', 'deleted_by',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /**
     * Note there is no SoftDeletes trait: the row must stay visible to chat
     * monitors, so nothing may scope it away globally.
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Reactions grouped for display: one entry per emoji with its count and
     * whether the viewer is among them.
     *
     * @return array<int,array{emoji:string,count:int,mine:bool}>
     */
    public function reactionSummary(?int $viewerId = null): array
    {
        return $this->reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'mine'  => $viewerId !== null && $group->contains('user_id', $viewerId),
            ])
            ->values()
            ->all();
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /** Images render inline as a thumbnail; everything else becomes a file chip. */
    public function attachmentIsImage(): bool
    {
        return $this->hasAttachment() && str_starts_with((string) $this->attachment_mime, 'image/');
    }

    /**
     * A recorded voice note rather than an uploaded file. The duration is only
     * ever set once the service has confirmed the upload really is audio, so
     * this can be trusted for rendering.
     */
    public function attachmentIsVoice(): bool
    {
        return $this->hasAttachment() && $this->attachment_duration !== null;
    }

    /** m:ss — what the player shows instead of the browser's "Infinity". */
    public function formattedAttachmentDuration(): string
    {
        $seconds = (int) $this->attachment_duration;

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public function attachmentSizeForHumans(): string
    {
        $bytes = (int) $this->attachment_size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
