<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A campaign as it exists on the platform.
 *
 * Distinct from AdCampaign, which is the hand-entered manual record and is left
 * untouched by syncing.
 */
class PlatformCampaign extends Model
{
    protected $fillable = [
        'brand_id', 'platform_ad_account_id', 'platform', 'external_id', 'name',
        'objective', 'status', 'buying_type', 'daily_budget', 'lifetime_budget',
        'started_at', 'stopped_at', 'metadata', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'       => 'array',
            'started_at'     => 'datetime',
            'stopped_at'     => 'datetime',
            'last_synced_at' => 'datetime',
            'daily_budget'    => 'decimal:2',
            'lifetime_budget' => 'decimal:2',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAdAccount::class, 'platform_ad_account_id');
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(PlatformAdSet::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(PlatformAdInsight::class);
    }
}
