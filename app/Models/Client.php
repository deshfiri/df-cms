<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'dfid_number', 'client_name', 'brand_name', 'website', 'contact_email', 'designs_link',
        'category_id', 'joining_date', 'assigned_to', 'client_status',
        'remarks', 'doc_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['joining_date' => 'date'];
    }

    public static array $statuses = ['Running', 'Warning', 'Completed', 'Hold', 'Cancelled'];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('dash.monthly_clients')
            + Cache::forget('dash.category_dist')
            + Cache::forget('dash.workflow_completion')
            + Cache::forget('dash.delayed_count')
            + Cache::forget('dash.pipeline_segments');

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function ownershipTransfers()
    {
        return $this->hasMany(ClientOwnershipTransfer::class)->latest();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function stageProgress()
    {
        return $this->hasMany(ClientStageProgress::class);
    }

    public function productUpdates()
    {
        return $this->hasMany(ProductUpdate::class)->latest();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class)->latest();
    }

    public function documents()
    {
        return $this->hasMany(Document::class)->latest();
    }

    public function clientDocuments()
    {
        return $this->hasMany(ClientDocument::class)->latest();
    }

    public function notes()
    {
        return $this->hasMany(ClientNote::class)->latest();
    }

    public function meetings()
    {
        return $this->hasMany(ClientMeeting::class)->latest('scheduled_at');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class)->latest();
    }

    // ── Computed ─────────────────────────────────────────────────────────────

    public function getProgressAttribute(): int
    {
        $total = WorkflowStage::where('status', true)->count();
        if ($total === 0) {
            return 0;
        }
        $completed = $this->stageProgress()->where('is_completed', true)->count();

        return (int) round(($completed / $total) * 100);
    }

    public function getLatestProductStatusAttribute(): ?string
    {
        return $this->productUpdates()->value('status');
    }

    public function getLatestPaymentStatusAttribute(): ?string
    {
        return $this->payments()->value('status');
    }

    public function getWebsiteUrlAttribute(): ?string
    {
        if (!$this->website) return null;
        if (str_starts_with($this->website, 'http://') || str_starts_with($this->website, 'https://')) {
            return $this->website;
        }
        return 'https://' . $this->website;
    }

    public function getDesignsLinkUrlAttribute(): ?string
    {
        if (!$this->designs_link) return null;
        if (str_starts_with($this->designs_link, 'http://') || str_starts_with($this->designs_link, 'https://')) {
            return $this->designs_link;
        }
        return 'https://' . $this->designs_link;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('client_name', 'like', "%{$term}%")
              ->orWhere('brand_name', 'like', "%{$term}%")
              ->orWhere('dfid_number', 'like', "%{$term}%")
              ->orWhere('website', 'like', "%{$term}%")
              ->orWhere('remarks', 'like', "%{$term}%")
              ->orWhereHas('category', fn ($q2) => $q2->where('name', 'like', "%{$term}%"))
              ->orWhereHas('notes', fn ($q2) => $q2->where('note', 'like', "%{$term}%"));
        });
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('client_status', $status);
    }
}
