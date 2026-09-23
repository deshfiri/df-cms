<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Target becomes a seventh performance KPI: completed work measured
 * against an employee's optional "N tasks a day" goal (see the daily_targets
 * table and PerformanceCalculationService::dailyTargetAchievement()).
 *
 *  - kpi_weight_configs.daily_target_weight      its weight in each profile
 *  - monthly_performance_snapshots.daily_target_score  kept with the other scores
 *
 * Existing weight profiles are rebalanced exactly as client_care was: daily
 * target takes 10, and the other six keep their proportions within the
 * remaining 90 (the total stays 100). Anyone with no daily target set is
 * unaffected — a KPI with no data is left out and the other weights are
 * re-normalised, as before.
 */
return new class extends Migration
{
    private const OTHERS = ['task_completion_weight', 'on_time_weight', 'revision_weight', 'sales_weight', 'satisfaction_weight', 'client_care_weight'];
    private const DAILY_TARGET_SHARE = 10;

    public function up(): void
    {
        Schema::table('kpi_weight_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('daily_target_weight')->default(0)->after('client_care_weight');
        });

        Schema::table('monthly_performance_snapshots', function (Blueprint $table) {
            $table->decimal('daily_target_score', 5, 2)->nullable()->after('client_care_score');
        });

        foreach (DB::table('kpi_weight_configs')->get() as $config) {
            DB::table('kpi_weight_configs')->where('id', $config->id)->update($this->rebalanced($config));
        }
    }

    public function down(): void
    {
        // Give daily target's share back to the others, in proportion.
        foreach (DB::table('kpi_weight_configs')->get() as $config) {
            $others = array_map(fn ($f) => (int) $config->{$f}, array_combine(self::OTHERS, self::OTHERS));
            DB::table('kpi_weight_configs')->where('id', $config->id)->update($this->scaled($others, 100));
        }

        Schema::table('monthly_performance_snapshots', fn (Blueprint $table) => $table->dropColumn('daily_target_score'));
        Schema::table('kpi_weight_configs', fn (Blueprint $table) => $table->dropColumn('daily_target_weight'));
    }

    /** @return array<string,int> */
    private function rebalanced(object $config): array
    {
        $others = [];
        foreach (self::OTHERS as $field) {
            $others[$field] = (int) $config->{$field};
        }

        return $this->scaled($others, 100 - self::DAILY_TARGET_SHARE) + ['daily_target_weight' => self::DAILY_TARGET_SHARE];
    }

    /**
     * Scale weights to a new total, keeping proportions; rounding leftovers go
     * to the largest weights so the total is exact.
     *
     * @param  array<string,int>  $weights
     * @return array<string,int>
     */
    private function scaled(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return array_map(fn () => intdiv($total, count($weights)), $weights);
        }

        $scaled = array_map(fn ($w) => (int) floor($w * $total / $sum), $weights);
        arsort($weights);
        foreach (array_keys($weights) as $field) {
            if (array_sum($scaled) >= $total) {
                break;
            }
            $scaled[$field]++;
        }

        return $scaled;
    }
};
