<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['user_one_id', 'user_two_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Get (or create) the single conversation between two users. */
    public static function between(int $userA, int $userB): self
    {
        $one = min($userA, $userB);
        $two = max($userA, $userB);

        return static::firstOrCreate(['user_one_id' => $one, 'user_two_id' => $two]);
    }

    public function hasParticipant(int|User $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->user_one_id === $id || $this->user_two_id === $id;
    }

    /** The id of the participant who isn't $userId. */
    public function otherParticipantId(int $userId): int
    {
        return $this->user_one_id === $userId ? $this->user_two_id : $this->user_one_id;
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId));
    }
}
