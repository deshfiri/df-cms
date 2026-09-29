<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One per brand, auto-created by App\Observers\InvoiceObserver once the
 * brand has a paid advertising-budget invoice and a content-charge invoice.
 * Not a fixed shape — see content_items. on_hold_at pauses new work without
 * ever touching existing content (see the SRS integration plan's Fix E/H).
 */
class BrandChecklist extends Model
{
    protected $fillable = ['brand_id', 'on_hold_at', 'on_hold_reason'];

    protected function casts(): array
    {
        return ['on_hold_at' => 'datetime'];
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function items()
    {
        return $this->hasMany(ContentItem::class, 'checklist_id');
    }

    public function isOnHold(): bool
    {
        return $this->on_hold_at !== null;
    }
}
