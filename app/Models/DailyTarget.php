<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's optional, standing "N tasks a day" goal, set by whoever holds
 * 'manage performance'. One per user — see the Daily Target KPI in
 * PerformanceCalculationService::dailyTargetAchievement().
 */
class DailyTarget extends Model
{
    use InvalidatesPerformanceBoard;

    protected $fillable = ['user_id', 'target_tasks_per_day', 'updated_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
