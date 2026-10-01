<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A brand may run several products at once — the shared checklist's content
 * items can optionally belong to one, so "which product is this raw content
 * for" is on record when a brand has more than one.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['brand_id', 'name', 'description', 'image', 'is_active', 'is_public', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_public' => 'boolean'];
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Opted into the public landing page by a Manager/Marketing user — see LandingController. */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
