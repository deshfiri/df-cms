<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiWeightConfig extends Model
{
    use InvalidatesPerformanceBoard;

    public const SCOPE_GLOBAL     = 'global';
    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_EMPLOYEE   = 'employee';

    protected $fillable = [
        'scope_type', 'scope_value',
        'task_completion_weight', 'on_time_weight', 'revision_weight', 'sales_weight', 'satisfaction_weight',
        'updated_by',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function toWeightsArray(): array
    {
        return [
            'task_completion' => $this->task_completion_weight,
            'on_time'         => $this->on_time_weight,
            'revision'        => $this->revision_weight,
            'sales'           => $this->sales_weight,
            'satisfaction'    => $this->satisfaction_weight,
        ];
    }
}
