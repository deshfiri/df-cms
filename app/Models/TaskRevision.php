<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRevision extends Model
{
    use InvalidatesPerformanceBoard;

    public const UPDATED_AT = null;

    public static array $reasonCategories = ['Employee Mistake', 'Client Requested', 'Scope Change', 'Management Requested'];

    protected $fillable = ['task_id', 'requested_by', 'reason_category', 'note', 'previous_status'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
