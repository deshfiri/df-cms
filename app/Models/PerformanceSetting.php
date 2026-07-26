<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;

class PerformanceSetting extends Model
{
    use InvalidatesPerformanceBoard;

    protected $fillable = [
        'task_weight_low', 'task_weight_medium', 'task_weight_high', 'task_weight_critical',
        'overload_threshold_pct', 'busy_threshold_pct', 'available_threshold_pct',
        'strict_workload_limit', 'auto_assign_enabled', 'count_cancelled_against_kpi',
        'revision_rate_alert_pct', 'kpi_drop_alert_points', 'overdue_alert_count',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'strict_workload_limit'       => 'boolean',
            'auto_assign_enabled'         => 'boolean',
            'count_cancelled_against_kpi' => 'boolean',
        ];
    }

    /**
     * The single settings row. We can't pin this to id=1 via firstOrCreate
     * because `id` isn't fillable (it would be dropped on insert, letting MySQL
     * assign an auto-increment id — so once the counter passes 1, every call
     * would create a fresh orphan row). Instead we take the earliest row, or
     * create one and reload it so the column DB defaults are populated.
     */
    public static function current(): self
    {
        $settings = static::query()->orderBy('id')->first();

        if (!$settings) {
            $settings = static::create([]);
            $settings->refresh();
        }

        return $settings;
    }

    public function priorityWeight(string $priority): int
    {
        return match ($priority) {
            'Low'    => $this->task_weight_low,
            'Medium' => $this->task_weight_medium,
            'High'   => $this->task_weight_high,
            'Urgent' => $this->task_weight_critical,
            default  => $this->task_weight_medium,
        };
    }

    public function workloadStatus(float $utilizationPct): string
    {
        return match (true) {
            $utilizationPct >= $this->overload_threshold_pct => 'Overloaded',
            $utilizationPct >= $this->busy_threshold_pct      => 'Busy',
            $utilizationPct >= $this->available_threshold_pct => 'Normal',
            default                                           => 'Available',
        };
    }
}
