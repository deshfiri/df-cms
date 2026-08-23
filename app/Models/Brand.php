<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'name', 'slug', 'logo', 'website', 'description',
        'is_active', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /** Hand-entered campaigns. Untouched by platform syncing. */
    public function adCampaigns()
    {
        return $this->hasMany(AdCampaign::class)->latest();
    }

    // ── Platform integrations ────────────────────────────────────────────

    public function integrations()
    {
        return $this->hasMany(BrandIntegration::class);
    }

    public function integrationFor(string $platform): ?BrandIntegration
    {
        return $this->integrations()->where('platform', $platform)->first();
    }

    public function platformAdAccounts()
    {
        return $this->hasMany(PlatformAdAccount::class);
    }

    public function platformCampaigns()
    {
        return $this->hasMany(PlatformCampaign::class);
    }

    public function insights()
    {
        return $this->hasMany(PlatformAdInsight::class);
    }

    public function syncLogs()
    {
        return $this->hasMany(SyncLog::class)->latest('started_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
