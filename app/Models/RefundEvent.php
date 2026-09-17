<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a refund's life. Written once, never changed. */
class RefundEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['refund_id', 'from_status', 'to_status', 'user_id', 'note', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
