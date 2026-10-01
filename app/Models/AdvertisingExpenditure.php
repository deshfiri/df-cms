<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * One entry against a brand's advertising budget. Corrections go through
 * PendingChange/ChangeApprovalService (see AdvertisingExpenditureService),
 * the same "a pending edit never touches the live row" rule PaymentService
 * already follows — so advertisingSpent() only ever reflects approved
 * amounts.
 *
 * SoftDeletes: a deleted row stays recoverable (withTrashed()) for audit —
 * the PendingChange/ActivityLog snapshot taken on deletion only ever covers
 * CORRECTABLE_FIELDS, not recorded_by/created_at/idempotency_key/id.
 */
class AdvertisingExpenditure extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'brand_id', 'ad_campaign_id', 'amount', 'reporting_date', 'note', 'recorded_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'reporting_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('dash.manager_brand_budgets');

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /** Reporting link only — never read by, or written to, AdCampaign's own budget math. */
    public function adCampaign()
    {
        return $this->belongsTo(AdCampaign::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
