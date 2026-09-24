<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyPerformanceSnapshot extends Model
{
    protected $fillable = [
        'user_id', 'period',
        'task_completion_score', 'on_time_score', 'revision_score', 'sales_score', 'satisfaction_score', 'client_care_score',
        'daily_target_score', 'output_volume_score', 'task_giving_score',
        'weights_used', 'component_details', 'final_score', 'performance_level',
        'rank_department', 'rank_company', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'weights_used'       => 'array',
            'component_details'  => 'array',
            'generated_at'       => 'datetime',
            'task_completion_score' => 'decimal:2',
            'on_time_score'          => 'decimal:2',
            'revision_score'         => 'decimal:2',
            'sales_score'            => 'decimal:2',
            'satisfaction_score'     => 'decimal:2',
            'client_care_score'      => 'decimal:2',
            'daily_target_score'     => 'decimal:2',
            'output_volume_score'    => 'decimal:2',
            'task_giving_score'      => 'decimal:2',
            'final_score'            => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
