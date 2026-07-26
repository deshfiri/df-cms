<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdCampaignDailyReport extends Model
{
    protected $fillable = [
        'ad_campaign_id', 'report_date', 'spend', 'sales', 'leads', 'orders',
        'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'spend'       => 'decimal:2',
            'sales'       => 'decimal:2',
            'leads'       => 'integer',
            'orders'      => 'integer',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getRoasAttribute(): ?float
    {
        return $this->spend > 0 ? round((float) $this->sales / (float) $this->spend, 2) : null;
    }

    public function getCplAttribute(): ?float
    {
        return $this->leads > 0 ? round((float) $this->spend / $this->leads, 2) : null;
    }

    public function getCpaAttribute(): ?float
    {
        return $this->orders > 0 ? round((float) $this->spend / $this->orders, 2) : null;
    }
}
