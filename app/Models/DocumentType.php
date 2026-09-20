<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentType extends Model
{
    protected $fillable = [
        'name', 'slug', 'icon', 'description', 'is_required', 'is_active', 'sort_order', 'is_client_submittable',
    ];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean', 'is_client_submittable' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentType $type) {
            if (empty($type->slug)) {
                $type->slug = Str::slug($type->name);
            }
        });
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class, 'document_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /** The order Settings → Document Types and every picker shows them in. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Whether anything is filed under this type.
     *
     * A type in use is deactivated rather than deleted, so old documents keep
     * their label — the database refuses the delete anyway.
     */
    public function isInUse(): bool
    {
        return $this->documents()->exists();
    }
}
