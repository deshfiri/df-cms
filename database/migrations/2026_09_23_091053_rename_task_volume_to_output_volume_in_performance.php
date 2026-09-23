<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Task Volume" broadens into "Output Volume": completed-work credit
 * relative to the company's highest, now averaged across every scope of
 * work tracked (tasks, workflow items, client handling — see
 * PerformanceCalculationService::outputVolume()), not tasks alone. Renaming
 * the columns rather than adding new ones — no real weight profile exists
 * yet to migrate data for, and "task_volume_weight" would be a misleading
 * name for a KPI that no longer measures tasks alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_weight_configs', function (Blueprint $table) {
            $table->renameColumn('task_volume_weight', 'output_volume_weight');
        });

        Schema::table('monthly_performance_snapshots', function (Blueprint $table) {
            $table->renameColumn('task_volume_score', 'output_volume_score');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_weight_configs', function (Blueprint $table) {
            $table->renameColumn('output_volume_weight', 'task_volume_weight');
        });

        Schema::table('monthly_performance_snapshots', function (Blueprint $table) {
            $table->renameColumn('output_volume_score', 'task_volume_score');
        });
    }
};
