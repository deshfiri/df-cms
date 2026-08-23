<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of performance for one entity, at whichever level it was reported.
 *
 * Derived rates (CTR, CPC, CPM, ROAS) are stored as the platform reported them
 * where available, but the dashboard recomputes totals from spend/impressions/
 * clicks — averaging an average across days would be wrong.
 */
class PlatformAdInsight extends Model
{
    public const LEVEL_ACCOUNT  = 'account';
    public const LEVEL_CAMPAIGN = 'campaign';
    public const LEVEL_AD_SET   = 'ad_set';
    public const LEVEL_AD       = 'ad';

    protected $fillable = [
        'brand_id', 'platform_ad_account_id', 'platform_campaign_id',
        'platform_ad_set_id', 'platform_ads_id', 'platform', 'level',
        'external_id', 'date', 'spend', 'impressions', 'reach', 'clicks',
        'ctr', 'cpc', 'cpm', 'conversions', 'conversion_value',
        'actions', 'metadata', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'date'             => 'date',
            'actions'          => 'array',
            'metadata'         => 'array',
            'spend'            => 'decimal:2',
            'conversion_value' => 'decimal:2',
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

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }
}
