<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A word or phrase blocked from the internal chat — see
 * App\Services\Chat\ChatWordFilter, which is what actually matches messages
 * against the active list.
 *
 * Deliberately not soft-deleted: unlike a payment category or document type,
 * nothing else references a row here, so removing one for good is always safe.
 */
class ForbiddenWord extends Model
{
    protected $fillable = ['word', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('word');
    }
}
