<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A single ad and its creative, flattened to fields every platform shares. */
class PlatformAd extends Model
{
    protected $table = 'platform_ads';

    protected $fillable = [
        'brand_id', 'platform_ad_set_id', 'platform', 'external_id', 'name', 'status',
        'creative_external_id', 'primary_text', 'headline', 'creative_description',
        'call_to_action', 'destination_url', 'thumbnail_url', 'preview_url',
        'metadata', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(PlatformAdSet::class, 'platform_ad_set_id');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(PlatformAdInsight::class, 'platform_ads_id');
    }
}
