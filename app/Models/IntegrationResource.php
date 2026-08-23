<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A thing the user picked out of their platform account — a Facebook page, an
 * Instagram account, an ad account, a pixel.
 *
 * Generic on purpose: a new platform brings new `type` values, not new tables.
 */
class IntegrationResource extends Model
{
    public const TYPE_PAGE       = 'page';
    public const TYPE_INSTAGRAM  = 'instagram';
    public const TYPE_AD_ACCOUNT = 'ad_account';
    public const TYPE_PIXEL      = 'pixel';
    public const TYPE_BUSINESS   = 'business';

    public static array $types = [
        self::TYPE_PAGE, self::TYPE_INSTAGRAM, self::TYPE_AD_ACCOUNT,
        self::TYPE_PIXEL, self::TYPE_BUSINESS,
    ];

    protected $fillable = [
        'brand_integration_id', 'type', 'external_id', 'name', 'status', 'metadata', 'is_selected',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_selected' => 'boolean'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(BrandIntegration::class, 'brand_integration_id');
    }

    public function scopeSelected($query)
    {
        return $query->where('is_selected', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
