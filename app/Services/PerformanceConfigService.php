<?php

namespace App\Services;

use App\Models\EmployeeCapacity;
use App\Models\KpiWeightConfig;
use App\Models\PerformanceSetting;
use App\Models\SalesTarget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes for the Performance configuration area (sales targets, KPI weight
 * configs, the settings singleton, and per-employee capacity). All changes are
 * transactional, stamp the acting user, and are recorded to the activity log
 * under the 'Performance' module (client_id null — these aren't client-scoped).
 */
class PerformanceConfigService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function upsertTarget(array $data): SalesTarget
    {
        return DB::transaction(function () use ($data) {
            $target = SalesTarget::firstOrNew([
                'user_id' => $data['user_id'],
                'period'  => $data['period'],
            ]);

            $old = $target->exists ? $target->only('target_amount') : null;
            if (!$target->exists) {
                $target->created_by = Auth::id();
            }
            $target->target_amount = $data['target_amount'];
            $target->updated_by    = Auth::id();
            $target->save();

            $this->activityLog->log('Performance', $old ? 'Sales Target Updated' : 'Sales Target Set', null, $old, [
                'user_id' => $target->user_id, 'period' => $target->period, 'target_amount' => $target->target_amount,
            ]);

            return $target;
        });
    }

    public function deleteTarget(SalesTarget $target): void
    {
        DB::transaction(function () use ($target) {
            $this->activityLog->log('Performance', 'Sales Target Removed', null, $target->only(['user_id', 'period', 'target_amount']), null);
            $target->delete();
        });
    }

    public function upsertWeight(array $data): KpiWeightConfig
    {
        return DB::transaction(function () use ($data) {
            $scopeValue = $data['scope_type'] === KpiWeightConfig::SCOPE_GLOBAL ? null : $data['scope_value'];

            $config = KpiWeightConfig::firstOrNew([
                'scope_type'  => $data['scope_type'],
                'scope_value' => $scopeValue,
            ]);

            $old = $config->exists ? $config->toWeightsArray() : null;
            $config->fill([
                'task_completion_weight' => $data['task_completion_weight'],
                'on_time_weight'         => $data['on_time_weight'],
                'revision_weight'        => $data['revision_weight'],
                'sales_weight'           => $data['sales_weight'],
                'satisfaction_weight'    => $data['satisfaction_weight'],
                'updated_by'             => Auth::id(),
            ]);
            $config->save();

            $this->activityLog->log('Performance', 'KPI Weights Saved', null, $old, [
                'scope' => $config->scope_type . ($scopeValue ? ":{$scopeValue}" : ''),
            ] + $config->toWeightsArray());

            return $config;
        });
    }

    public function deleteWeight(KpiWeightConfig $config): void
    {
        DB::transaction(function () use ($config) {
            $this->activityLog->log('Performance', 'KPI Weights Removed', null, [
                'scope' => $config->scope_type . ($config->scope_value ? ":{$config->scope_value}" : ''),
            ] + $config->toWeightsArray(), null);
            $config->delete();
        });
    }

    public function updateSettings(array $data): PerformanceSetting
    {
        return DB::transaction(function () use ($data) {
            $settings = PerformanceSetting::current();
            $old = $settings->only(array_keys($data));
            $settings->fill($data + ['updated_by' => Auth::id()]);
            $settings->save();

            $this->activityLog->log('Performance', 'Settings Updated', null, $old, $data);

            return $settings;
        });
    }

    public function upsertCapacity(array $data): EmployeeCapacity
    {
        return DB::transaction(function () use ($data) {
            $capacity = EmployeeCapacity::firstOrNew(['user_id' => $data['user_id']]);

            $old = $capacity->exists ? $capacity->only(['working_hours_per_day', 'working_days_per_week', 'max_active_tasks', 'max_workload_points']) : null;
            $capacity->fill([
                'working_hours_per_day' => $data['working_hours_per_day'],
                'working_days_per_week' => $data['working_days_per_week'],
                'max_active_tasks'      => $data['max_active_tasks'] ?? null,
                'max_workload_points'   => $data['max_workload_points'] ?? null,
                'updated_by'            => Auth::id(),
            ]);
            $capacity->save();

            $this->activityLog->log('Performance', 'Capacity Updated', null, $old, $capacity->only(['user_id', 'working_hours_per_day', 'working_days_per_week', 'max_active_tasks', 'max_workload_points']));

            return $capacity;
        });
    }
}
