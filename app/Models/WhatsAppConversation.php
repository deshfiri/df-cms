<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's thread with one brand number.
 *
 * The load-bearing method here is {@see scopeVisibleTo()}. Every query that can
 * reach a conversation must go through it, so that authorization is applied in
 * SQL rather than after the rows have already been fetched.
 */
class WhatsAppConversation extends Model
{
    public const STATUS_OPEN    = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLOSED  = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_PENDING, self::STATUS_CLOSED];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * Meta's customer service window.
     *
     * Free-form replies are only permitted for this long after the customer's
     * last message; outside it, an approved template is the only way to write.
     * Held as a constant with a name rather than a literal 24 scattered around,
     * because it is Meta policy and can change.
     */
    public const SERVICE_WINDOW_HOURS = 24;

    /** Explicit: the convention would look for `whats_app_conversations`. */
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'brand_id', 'whatsapp_account_id', 'whatsapp_contact_id',
        'assigned_user_id', 'assigned_by', 'assigned_at',
        'status', 'priority', 'last_message_at', 'last_message_preview',
        'unread_count', 'last_customer_message_at', 'closed_at', 'closed_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'                 => 'array',
            'unread_count'             => 'integer',
            'assigned_at'              => 'datetime',
            'last_message_at'          => 'datetime',
            'last_customer_message_at' => 'datetime',
            'closed_at'                => 'datetime',
        ];
    }

    // ── Authorization ────────────────────────────────────────────────────

    /**
     * Restrict a query to the conversations this user may see.
     *
     * This is the single definition of WhatsApp visibility. A Super Admin, or
     * anyone holding "view all whatsapp", sees everything; everyone else sees
     * only what is assigned to them. Nothing else grants access — an unassigned
     * conversation is deliberately invisible to an ordinary agent until someone
     * with "assign whatsapp" hands it over.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('Super Admin') || $user->can('view all whatsapp')) {
            return $query;
        }

        return $query->where('assigned_user_id', $user->id);
    }

    /** Whether free-form (non-template) messaging is currently permitted. */
    public function withinServiceWindow(): bool
    {
        return $this->last_customer_message_at !== null
            && $this->last_customer_message_at->gt(now()->subHours(self::SERVICE_WINDOW_HOURS));
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeForBrand(Builder $query, ?int $brandId): Builder
    {
        return $brandId ? $query->where('brand_id', $brandId) : $query;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('unread_count', '>', 0);
    }

    /** Name, profile name or phone — matched without loading every contact. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (!filled($term)) {
            return $query;
        }

        $like = '%' . $term . '%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereHas('contact', fn (Builder $c) => $c
                ->where('name', 'like', $like)
                ->orWhere('profile_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('wa_id', 'like', $like))
              ->orWhere('last_message_preview', 'like', $like);
        });
    }
}
