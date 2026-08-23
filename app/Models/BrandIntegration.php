<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One brand's connection to one advertising platform.
 *
 * `credentials` holds the access token and friends. It is encrypted by the cast
 * and listed in $hidden, so it cannot leak through a toArray()/toJson() — the
 * usual way a token ends up in an API response by accident.
 */
class BrandIntegration extends Model
{
    public const PLATFORM_META = 'meta';

    public const STATUS_CONNECTED    = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_TOKEN_EXPIRED = 'token_expired';
    public const STATUS_ERROR        = 'error';

    protected $fillable = [
        'brand_id', 'platform', 'status', 'credentials', 'metadata',
        'connected_at', 'last_synced_at', 'token_expires_at', 'last_error', 'connected_by',
    ];

    /** Never serialise the token, whatever the caller does. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials'      => 'encrypted:array',
            'metadata'         => 'array',
            'connected_at'     => 'datetime',
            'last_synced_at'   => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(IntegrationResource::class);
    }

    public function adAccounts(): HasMany
    {
        return $this->hasMany(PlatformAdAccount::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class)->latest('started_at');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /** A token with no expiry recorded is treated as still good. */
    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /** Ready to be synced: connected, and the token has not lapsed. */
    public function isSyncable(): bool
    {
        return $this->isConnected() && !$this->tokenHasExpired();
    }

    public function accessToken(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }

    /** The next automatic run, for the "next sync" readout. */
    public function nextSyncAt(): ?\Illuminate\Support\Carbon
    {
        return $this->last_synced_at?->copy()->addMinutes(20);
    }

    public function scopeSyncable($query, string $platform = self::PLATFORM_META)
    {
        return $query->where('platform', $platform)
            ->where('status', self::STATUS_CONNECTED);
    }
}
