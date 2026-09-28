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

    public function products()
    {
        return $this->hasMany(Product::class)->latest();
    }

    /** Charges billed against this specific brand (advertising budget, content charges). */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Money actually paid toward this brand's advertising, summed across every
     * "Social Media Ads" charge it has ever had — not just one invoice, so a
     * later top-up charge adds to the same pool. Unpaid/partially-paid amounts
     * on those charges are not part of the budget yet; Invoice::getPaidAmountAttribute()
     * already nets out completed refunds per charge.
     */
    public function advertisingBudget(): float
    {
        return round((float) $this->invoices()
            ->whereHas('category', fn ($q) => $q->where('name', PaymentCategory::NAME_ADVERTISING_BUDGET))
            ->get()
            ->sum(fn (Invoice $i) => $i->paid_amount), 2);
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

    // ── WhatsApp ─────────────────────────────────────────────────────────
    // A brand may hold any number of WhatsApp numbers; the relationship is
    // hasMany so "one number per brand" is never assumed.

    public function whatsappAccounts()
    {
        return $this->hasMany(WhatsAppAccount::class);
    }

    public function whatsappConversations()
    {
        return $this->hasMany(WhatsAppConversation::class);
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
