<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdCampaign extends Model
{
    use SoftDeletes;

    public static array $statuses = ['Active', 'Paused', 'Completed', 'Cancelled'];
    public static array $platforms = ['Facebook', 'Instagram', 'Google', 'TikTok', 'YouTube', 'Other'];

    protected $fillable = [
        'client_id',
        'brand_id',
        'assigned_to',
        'name',
        'platform',
        'budget',
        'status',
        'start_date',
        'end_date',
        'remarks',
        'created_by',
        'updated_by',
    ];

    private ?object $reportTotalsCache = null;

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function dailyReports()
    {
        return $this->hasMany(AdCampaignDailyReport::class)->orderBy('report_date');
    }

    public function assignmentHistory()
    {
        return $this->hasMany(AdCampaignAssignment::class)->latest();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    private function reportTotals(): object
    {
        if ($this->reportTotalsCache === null) {
            $this->reportTotalsCache = $this->dailyReports()->selectRaw(
                'COALESCE(SUM(spend),0) as spend, COALESCE(SUM(sales),0) as sales, ' .
                'COALESCE(SUM(leads),0) as leads, COALESCE(SUM(orders),0) as orders'
            )->first();
        }

        return $this->reportTotalsCache;
    }

    public function getTotalSpendAttribute(): float
    {
        return (float) $this->reportTotals()->spend;
    }

    public function getTotalSalesAttribute(): float
    {
        return (float) $this->reportTotals()->sales;
    }

    public function getTotalLeadsAttribute(): int
    {
        return (int) $this->reportTotals()->leads;
    }

    public function getTotalOrdersAttribute(): int
    {
        return (int) $this->reportTotals()->orders;
    }

    public function getRoasAttribute(): ?float
    {
        return $this->total_spend > 0 ? round($this->total_sales / $this->total_spend, 2) : null;
    }

    public function getCplAttribute(): ?float
    {
        return $this->total_leads > 0 ? round($this->total_spend / $this->total_leads, 2) : null;
    }

    public function getCpaAttribute(): ?float
    {
        return $this->total_orders > 0 ? round($this->total_spend / $this->total_orders, 2) : null;
    }

    public function getBudgetRemainingAttribute(): ?float
    {
        return $this->budget !== null ? round((float) $this->budget - $this->total_spend, 2) : null;
    }
}
