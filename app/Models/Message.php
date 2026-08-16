<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'read_at',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
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
