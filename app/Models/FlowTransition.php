<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['flow_item_id', 'from_stage_id', 'to_stage_id', 'moved_by', 'note'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FlowItem::class, 'flow_item_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(FlowStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(FlowStage::class, 'to_stage_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
