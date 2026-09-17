<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a task.
 *
 * `action` / `description` are the human wording shown in the history.
 * `event` / `meta` are the same thing in structured form — what the involvement
 * and performance calculations read. Rows logged before events existed have a
 * null event; TaskInvolvementService::eventOf() infers theirs from the action.
 */
class TaskActivity extends Model
{
    protected $fillable = ['task_id', 'user_id', 'action', 'event', 'meta', 'description', 'old_value', 'new_value'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
