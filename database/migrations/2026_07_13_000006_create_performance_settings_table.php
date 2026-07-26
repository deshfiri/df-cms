<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('task_weight_low')->default(1);
            $table->unsignedTinyInteger('task_weight_medium')->default(2);
            $table->unsignedTinyInteger('task_weight_high')->default(3);
            $table->unsignedTinyInteger('task_weight_critical')->default(5);
            $table->unsignedInteger('overload_threshold_pct')->default(100);
            $table->unsignedInteger('busy_threshold_pct')->default(80);
            $table->unsignedInteger('available_threshold_pct')->default(50);
            $table->boolean('strict_workload_limit')->default(false);
            $table->boolean('auto_assign_enabled')->default(false);
            $table->boolean('count_cancelled_against_kpi')->default(false);
            $table->unsignedInteger('revision_rate_alert_pct')->default(20);
            $table->unsignedInteger('kpi_drop_alert_points')->default(10);
            $table->unsignedInteger('overdue_alert_count')->default(3);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_settings');
    }
};
