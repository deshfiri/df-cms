<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An advertising account on a platform, owned by one brand. */
class PlatformAdAccount extends Model
{
    protected $fillable = [
        'brand_id', 'brand_integration_id', 'platform', 'external_id',
        'name', 'currency', 'timezone', 'status', 'metadata', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(BrandIntegration::class, 'brand_integration_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(PlatformCampaign::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(PlatformAdInsight::class);
    }
}
