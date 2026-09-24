<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task Giving Quality becomes a ninth performance KPI: of the tasks someone
 * handed out, what share came back clean rather than needing a "Task Giver
 * Mistake" revision (missing information, an unclear brief, etc.) — the
 * mirror image of Quality/revisionRate(), but scored against the person who
 * gave the work out rather than the person who did it. A revision the
 * assignee requests for this reason never counts against their own
 * revision-rate KPI — only "Employee Mistake" does (see
 * PerformanceCalculationService::revisionRate(), which already only reads
 * that category) — so the assignee is never penalised for a mistake that
 * was the task giver's. See
 * PerformanceCalculationService::taskGivingQuality().
 *
 *  - kpi_weight_configs.task_giving_weight          its weight in each profile
 *  - monthly_performance_snapshots.task_giving_score kept with the other scores
 *
 * Existing weight profiles are rebalanced so it counts straight away: it
 * takes 10, and the other nine keep their proportions within the remaining
 * 90 (the total stays 100). Anyone who has never given out a task in the
 * period is unaffected — a KPI with no data is left out and the other
 * weights are re-normalised, as every other optional KPI already works.
 */
return new class extends Migration
{
    private const OTHERS = [
        'task_completion_weight', 'on_time_weight', 'revision_weight', 'sales_weight',
        'satisfaction_weight', 'client_care_weight', 'daily_target_weight', 'output_volume_weight',
    ];
    private const TASK_GIVING_SHARE = 10;

    public function up(): void
    {
        Schema::table('kpi_weight_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('task_giving_weight')->default(0)->after('output_volume_weight');
        });

        Schema::table('monthly_performance_snapshots', function (Blueprint $table) {
            $table->decimal('task_giving_score', 5, 2)->nullable()->after('output_volume_score');
        });

        foreach (DB::table('kpi_weight_configs')->get() as $config) {
            DB::table('kpi_weight_configs')->where('id', $config->id)->update($this->rebalanced($config));
        }
    }

    public function down(): void
    {
        // Give task giving's share back to the others, in proportion.
        foreach (DB::table('kpi_weight_configs')->get() as $config) {
            $others = array_map(fn ($f) => (int) $config->{$f}, array_combine(self::OTHERS, self::OTHERS));
            DB::table('kpi_weight_configs')->where('id', $config->id)->update($this->scaled($others, 100));
        }

        Schema::table('monthly_performance_snapshots', fn (Blueprint $table) => $table->dropColumn('task_giving_score'));
        Schema::table('kpi_weight_configs', fn (Blueprint $table) => $table->dropColumn('task_giving_weight'));
    }

    /** @return array<string,int> */
    private function rebalanced(object $config): array
    {
        $others = [];
        foreach (self::OTHERS as $field) {
            $others[$field] = (int) $config->{$field};
        }

        return $this->scaled($others, 100 - self::TASK_GIVING_SHARE) + ['task_giving_weight' => self::TASK_GIVING_SHARE];
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
