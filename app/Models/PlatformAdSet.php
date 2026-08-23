<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ad set / ad group: budget, schedule and targeting under a campaign. */
class PlatformAdSet extends Model
{
    protected $fillable = [
        'brand_id', 'platform_campaign_id', 'platform', 'external_id', 'name',
        'status', 'optimization_goal', 'billing_event', 'daily_budget', 'lifetime_budget',
        'starts_at', 'ends_at', 'targeting', 'metadata', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'targeting'      => 'array',
            'metadata'       => 'array',
            'starts_at'      => 'datetime',
            'ends_at'        => 'datetime',
            'last_synced_at' => 'datetime',
            'daily_budget'    => 'decimal:2',
            'lifetime_budget' => 'decimal:2',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PlatformCampaign::class, 'platform_campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(PlatformAd::class);
    }
}
