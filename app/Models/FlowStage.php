<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class FlowStage extends Model
{
    protected $fillable = ['flow_id', 'name', 'position'];

    /** Adding, renaming or removing a stage changes the dashboard's workflow widgets when this is the lead flow. */
    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('dash.workflow_completion') + Cache::forget('dash.pipeline_segments');

        static::saved($flush);
        static::deleted($flush);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /** Users assigned to work this stage. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'flow_stage_user')->withTimestamps();
    }

    /** Items currently sitting at this stage. */
    public function items(): HasMany
    {
        return $this->hasMany(FlowItem::class, 'current_stage_id');
    }

    /** The next stage in the flow by position, or null if this is the last. */
    public function nextStage(): ?FlowStage
    {
        return static::where('flow_id', $this->flow_id)
            ->where('position', '>', $this->position)
            ->orderBy('position')
            ->first();
    }

    public function hasUser(int $userId): bool
    {
        return $this->users()->whereKey($userId)->exists();
    }
}
