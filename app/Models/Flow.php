<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flow extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'is_active', 'client_visible', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'client_visible' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(FlowStage::class)->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FlowItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function firstStage(): ?FlowStage
    {
        return $this->stages()->orderBy('position')->first();
    }
}
