<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single webhook delivery from Meta.
 *
 * The row is written before anything is processed, and its unique hash is what
 * makes a retried delivery a no-op.
 */
class WhatsAppWebhookEvent extends Model
{
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED   = 'ignored';
    public const STATUS_FAILED    = 'failed';

    /** Explicit: the convention would look for `whats_app_webhook_events`. */
    protected $table = 'whatsapp_webhook_events';

    protected $fillable = [
        'signature_hash', 'phone_number_id', 'whatsapp_account_id',
        'event_type', 'status', 'payload', 'error', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** The identity of a delivery: what Meta sent, byte for byte. */
    public static function hashFor(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    public function markProcessed(?string $eventType = null): void
    {
        $this->forceFill([
            'status'       => self::STATUS_PROCESSED,
            'event_type'   => $eventType ?? $this->event_type,
            'processed_at' => now(),
        ])->save();
    }

    public function markIgnored(string $reason): void
    {
        $this->forceFill([
            'status'       => self::STATUS_IGNORED,
            'error'        => $reason,
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status'       => self::STATUS_FAILED,
            'error'        => $error,
            'processed_at' => now(),
        ])->save();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
