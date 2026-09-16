<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread: either a direct conversation between two people, or a named
 * group with a member list.
 *
 * Direct conversations keep their canonical pair (user_one_id < user_two_id) and
 * never touch the member table. Groups have no pair; their people live in
 * `conversation_user`, each with a role and their own read marker.
 */
class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';
    public const TYPE_GROUP  = 'group';

    public const ROLE_OWNER  = 'owner';
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_MEMBER = 'member';

    protected $fillable = ['type', 'name', 'created_by', 'user_one_id', 'user_two_id', 'last_message_at'];

    protected $attributes = ['type' => self::TYPE_DIRECT];

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A group's people. Empty for a direct conversation. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'last_read_message_id'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    /** Get (or create) the single conversation between two users. */
    public static function between(int $userA, int $userB): self
    {
        $one = min($userA, $userB);
        $two = max($userA, $userB);

        return static::firstOrCreate(
            ['user_one_id' => $one, 'user_two_id' => $two],
            ['type' => self::TYPE_DIRECT],
        );
    }

    public function hasParticipant(int|User $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        if ($this->isGroup()) {
            return $this->relationLoaded('members')
                ? $this->members->contains('id', $id)
                : $this->members()->whereKey($id)->exists();
        }

        return (int) $this->user_one_id === (int) $id || (int) $this->user_two_id === (int) $id;
    }

    /** A member's role in this group, or null when they are not in it. */
    public function roleOf(int|User $user): ?string
    {
        if (!$this->isGroup()) {
            return null;
        }

        $id = $user instanceof User ? $user->id : $user;

        $member = $this->relationLoaded('members')
            ? $this->members->firstWhere('id', $id)
            : $this->members()->whereKey($id)->first();

        return $member?->pivot->role;
    }

    /** Owners and admins may rename a group and change who is in it. */
    public function canBeManagedBy(int|User $user): bool
    {
        return in_array($this->roleOf($user), [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    /** The id of the participant who isn't $userId. Direct conversations only. */
    public function otherParticipantId(int $userId): int
    {
        return (int) $this->user_one_id === $userId ? (int) $this->user_two_id : (int) $this->user_one_id;
    }

    /**
     * Everyone a new message should reach, apart from whoever sent it.
     *
     * @return array<int,int>
     */
    public function recipientIdsFor(int $senderId): array
    {
        if (!$this->isGroup()) {
            return [$this->otherParticipantId($senderId)];
        }

        return $this->members()
            ->where('users.id', '!=', $senderId)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Conversations the user takes part in: their pairs and the groups they belong to. */
    public function scopeForUser($query, int $userId)
    {
        return $query->where(fn ($q) => $q
            ->where('user_one_id', $userId)
            ->orWhere('user_two_id', $userId)
            ->orWhereHas('members', fn ($m) => $m->whereKey($userId)));
    }
}
