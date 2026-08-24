<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    public const DIRECTION_IN  = 'incoming';
    public const DIRECTION_OUT = 'outgoing';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ      = 'read';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_RECEIVED  = 'received';

    /**
     * Delivery only ever moves forwards.
     *
     * Meta does not guarantee status webhooks arrive in order, so a late "sent"
     * must not undo a "read" that already landed. Ranked here, compared in
     * {@see advancesTo()}.
     */
    private const STATUS_RANK = [
        self::STATUS_PENDING   => 0,
        self::STATUS_SENT      => 1,
        self::STATUS_DELIVERED => 2,
        self::STATUS_READ      => 3,
    ];

    public const TYPES = [
        'text', 'image', 'video', 'audio', 'document', 'sticker',
        'location', 'template', 'interactive', 'reaction', 'contacts', 'unknown',
    ];

    /** Explicit: the convention would look for `whats_app_messages`. */
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'whatsapp_conversation_id', 'wamid', 'direction', 'type', 'body', 'context_wamid',
        'media_id', 'media_disk', 'media_path', 'media_name', 'media_mime', 'media_size',
        'status', 'error_code', 'error_message', 'sent_by_user_id', 'template_name',
        'metadata', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'     => 'array',
            'media_size'   => 'integer',
            'sent_at'      => 'datetime',
            'delivered_at' => 'datetime',
            'read_at'      => 'datetime',
            'failed_at'    => 'datetime',
        ];
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    public function hasMedia(): bool
    {
        return $this->media_path !== null;
    }

    /**
     * Whether a newly reported status is actually newer than the one held.
     *
     * "failed" always wins — it is terminal and never arrives speculatively.
     */
    public function advancesTo(string $status): bool
    {
        if ($status === self::STATUS_FAILED) {
            return $this->status !== self::STATUS_FAILED;
        }

        return (self::STATUS_RANK[$status] ?? -1) > (self::STATUS_RANK[$this->status] ?? -1);
    }

    /**
     * One line describing this message, for the conversation list.
     *
     * A media message has no body of its own, so it is named by what it is.
     */
    public function previewLine(): string
    {
        if (filled($this->body)) {
            return $this->body;
        }

        return match ($this->type) {
            'image'       => '📷 Photo',
            'video'       => '🎥 Video',
            'audio'       => '🎤 Voice message',
            'document'    => '📎 ' . ($this->media_name ?: 'Document'),
            'sticker'     => '🌟 Sticker',
            'location'    => '📍 Location',
            'contacts'    => '👤 Contact card',
            'template'    => '📄 Template: ' . ($this->template_name ?: 'message'),
            'interactive' => '🔘 Interactive message',
            'reaction'    => '❤️ Reaction',
            default       => 'Message',
        };
    }

    public function mediaSizeForHumans(): string
    {
        $bytes = (int) $this->media_size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
