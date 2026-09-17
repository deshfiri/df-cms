<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client care becomes a sixth performance KPI: adding clients and keeping the
 * clients you are responsible for looked after. See the "Client care" section of
 * PerformanceCalculationService for exactly what counts.
 *
 *  - kpi_weight_configs.client_care_weight          its weight in each profile
 *  - performance_settings.client_care_target_points the monthly points that make 100%
 *  - monthly_performance_snapshots.client_care_score kept with the other scores
 *
 * Existing weight profiles are rebalanced so client care counts straight away:
 * it takes 15, and the other five keep their proportions within the remaining
 * 85 (the total stays 100). A profile's owner can change this on Performance →
 * Configuration. Anyone with no clients to care for is unaffected — a KPI with
 * no data is left out and the other weights are re-normalised, as before.
 */
return new class extends Migration
{
    private const OTHERS = ['task_completion_weight', 'on_time_weight', 'revision_weight', 'sales_weight', 'satisfaction_weight'];
    private const CLIENT_CARE_SHARE = 15;

    public function up(): void
    {
        Schema::table('kpi_weight_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('client_care_weight')->default(0)->after('satisfaction_weight');
        });

        Schema::table('performance_settings', function (Blueprint $table) {
            $table->unsignedInteger('client_care_target_points')->default(20)->after('overdue_alert_count');
        });

        Schema::table('monthly_performance_snapshots', function (Blueprint $table) {
            $table->decimal('client_care_score', 5, 2)->nullable()->after('satisfaction_score');
        });

        foreach (DB::table('kpi_weight_configs')->get() as $config) {
            DB::table('kpi_weight_configs')->where('id', $config->id)->update($this->rebalanced($config));
        }
    }

    public function down(): void
    {
        // Give client care's share back to the others, in proportion.
        foreach (DB::table('kpi_weight_configs')->get() as $config) {
            $others = array_map(fn ($f) => (int) $config->{$f}, array_combine(self::OTHERS, self::OTHERS));
            DB::table('kpi_weight_configs')->where('id', $config->id)->update($this->scaled($others, 100));
        }

        Schema::table('monthly_performance_snapshots', fn (Blueprint $table) => $table->dropColumn('client_care_score'));
        Schema::table('performance_settings', fn (Blueprint $table) => $table->dropColumn('client_care_target_points'));
        Schema::table('kpi_weight_configs', fn (Blueprint $table) => $table->dropColumn('client_care_weight'));
    }

    /** @return array<string,int> */
    private function rebalanced(object $config): array
    {
        $others = [];
        foreach (self::OTHERS as $field) {
            $others[$field] = (int) $config->{$field};
        }

        return $this->scaled($others, 100 - self::CLIENT_CARE_SHARE) + ['client_care_weight' => self::CLIENT_CARE_SHARE];
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
