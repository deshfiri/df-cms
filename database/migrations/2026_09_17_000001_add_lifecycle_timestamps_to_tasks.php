<?php

use App\Support\TaskLifecycleBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exact moments in a task's life.
 *
 * start_date / due_date / completion_date are calendar days — the plan. These
 * are the moments: when work actually started, the deadline to the minute, and
 * when the task was actually finished. The day columns stay, kept in step by
 * the Task model, because reports and screens across the app group by day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable()->after('start_date');
            $table->dateTime('due_at')->nullable()->after('due_date');
            $table->dateTime('completed_at')->nullable()->after('completion_date');

            $table->index('due_at');
            $table->index(['assigned_to', 'completed_at']);
        });

        TaskLifecycleBackfill::run();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['assigned_to', 'completed_at']);
            $table->dropIndex(['due_at']);
            $table->dropColumn(['started_at', 'due_at', 'completed_at']);
        });
    }
};
