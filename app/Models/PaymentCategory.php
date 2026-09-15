<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a charge is for — "Social Media Ads", "Website Development".
 *
 * Deliberately not soft-deleted: a category that has ever been billed against is
 * deactivated instead (see PaymentCategoryController::destroy), so historical
 * charges keep their label and only unused categories are ever removed.
 */
class PaymentCategory extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isInUse(): bool
    {
        return $this->invoices()->withTrashed()->exists() || $this->payments()->exists();
    }
}
