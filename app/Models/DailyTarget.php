<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's optional, standing "N a day" goal on one scope of work, set
 * by whoever holds 'manage performance'. A user may have a target on any
 * subset of $scopes at once (one row each) — see the Daily Target KPI in
 * PerformanceCalculationService::dailyTargetAchievement().
 */
class DailyTarget extends Model
{
    use InvalidatesPerformanceBoard;

    public const SCOPE_TASK     = 'task';
    public const SCOPE_WORKFLOW = 'workflow';

    /** Every scope a target can be set on — checkboxes on the config screen read this. */
    public static array $scopes = [self::SCOPE_TASK, self::SCOPE_WORKFLOW];

    public static array $scopeLabels = [
        self::SCOPE_TASK     => 'Tasks',
        self::SCOPE_WORKFLOW => 'Workflow Items',
    ];

    protected $fillable = ['user_id', 'scope', 'target_quantity', 'updated_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
