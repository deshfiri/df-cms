<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowItemComment extends Model
{
    protected $fillable = ['flow_item_id', 'user_id', 'body'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FlowItem::class, 'flow_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
