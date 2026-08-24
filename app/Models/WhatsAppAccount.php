<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * A connected WhatsApp Business number, owned by a Brand.
 *
 * The access token never leaves this class in readable form except through
 * {@see accessToken()}, and never appears in serialisation at all.
 */
class WhatsAppAccount extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING      = 'pending';
    public const STATUS_CONNECTED    = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_ERROR        = 'error';
    public const STATUS_DISABLED     = 'disabled';

    /**
     * Declared explicitly: Laravel's convention would split the class name at
     * the capital in "App" and look for `whats_app_accounts`.
     */
    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'brand_id', 'waba_id', 'phone_number_id', 'display_phone_number', 'verified_name',
        'access_token', 'token_expires_at', 'status', 'webhook_subscribed',
        'last_webhook_at', 'metadata', 'connected_by',
    ];

    /**
     * The token is hidden rather than merely un-selected: a stray toJson() on a
     * model that happens to have it loaded must not leak it to a browser.
     */
    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'metadata'           => 'array',
            'webhook_subscribed' => 'boolean',
            'token_expires_at'   => 'datetime',
            'last_webhook_at'    => 'datetime',
        ];
    }

    // ── Credentials ──────────────────────────────────────────────────────

    /**
     * The decrypted token, for the service layer only.
     *
     * A token written before encryption, or under a different APP_KEY, must not
     * fatal — it reads as "no token", which callers already treat as unusable.
     */
    public function accessToken(): ?string
    {
        if (!filled($this->access_token)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->access_token);
        } catch (DecryptException) {
            return null;
        }
    }

    public function setAccessToken(?string $token): void
    {
        $this->access_token = filled($token) ? Crypt::encryptString($token) : null;
    }

    // ── State ────────────────────────────────────────────────────────────

    /** Connected, not disabled, and holding a token we can actually decrypt. */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && filled($this->accessToken())
            && !$this->tokenHasExpired();
    }

    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /** Why this account cannot send, in words an agent can act on. */
    public function unusableReason(): ?string
    {
        if ($this->isUsable()) {
            return null;
        }

        return match (true) {
            $this->status === self::STATUS_DISABLED     => 'This number has been disabled.',
            $this->status === self::STATUS_DISCONNECTED => 'This number is disconnected. Reconnect it in WhatsApp → Numbers.',
            $this->status === self::STATUS_PENDING      => 'This number has not finished connecting yet.',
            $this->tokenHasExpired()                    => 'The access token for this number has expired. Reconnect it.',
            !filled($this->accessToken())               => 'No usable access token is stored for this number. Reconnect it.',
            default                                     => 'This number is not currently able to send messages.',
        };
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'whatsapp_account_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeConnected($query)
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }
}
