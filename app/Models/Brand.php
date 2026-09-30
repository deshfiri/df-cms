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

    public function checklist()
    {
        return $this->hasOne(BrandChecklist::class);
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

    public function advertisingExpenditures()
    {
        return $this->hasMany(AdvertisingExpenditure::class);
    }

    /** Sum of every recorded expenditure — approved amounts only (see AdvertisingExpenditure). */
    public function advertisingSpent(): float
    {
        return round((float) $this->advertisingExpenditures()->sum('amount'), 2);
    }

    /** Budget minus spent — signed, never floored at 0. A negative figure means overspent, not "$0 left." */
    public function advertisingRemaining(): float
    {
        return round($this->advertisingBudget() - $this->advertisingSpent(), 2);
    }

    public function isAdvertisingOverspent(): bool
    {
        return $this->advertisingRemaining() < 0;
    }

    public function advertisingOverspentAmount(): float
    {
        return round(abs(min(0.0, $this->advertisingRemaining())), 2);
    }

    /**
     * Whether new expenditure may currently be recorded — independent of
     * the brand's checklist or its hold state entirely (a checklist may not
     * exist yet, or may be on hold for unrelated content reasons, and
     * expenditure recording must not care either way). The only thing that
     * matters is whether the underlying paid Social Media Ads budget is
     * still actually there: it goes to $0 only via a refund/reversal or a
     * cancelled charge, both already reflected live by advertisingBudget()
     * (Invoice::getPaidAmountAttribute() nets out completed refunds, and a
     * cancelled invoice's payments no longer count toward any category).
     * Already-recorded expenditure is never affected by this — it stays on
     * the books exactly as it was; this only gates *new* entries.
     */
    public function hasAvailableAdvertisingBudget(): bool
    {
        return $this->advertisingBudget() > 0;
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
