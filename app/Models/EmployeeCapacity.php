<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCapacity extends Model
{
    protected $fillable = [
        'user_id', 'working_hours_per_day', 'working_days_per_week',
        'max_active_tasks', 'max_workload_points', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['working_hours_per_day' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getWeeklyHoursAttribute(): float
    {
        return (float) $this->working_hours_per_day * $this->working_days_per_week;
    }
}
