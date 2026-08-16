<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReaction extends Model
{
    /**
     * A fixed set, not free text. Arbitrary strings from the client would need
     * escaping everywhere they render and invite abuse as a second message
     * channel; six is enough to be expressive.
     */
    public const ALLOWED = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    protected $fillable = ['message_id', 'user_id', 'emoji'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
