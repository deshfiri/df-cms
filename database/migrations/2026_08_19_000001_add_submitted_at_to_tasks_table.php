<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the assignee handed the task back for review.
 *
 * A task now has a step between "being worked on" and "done": the assignee
 * submits it, and whoever assigned it accepts or sends it back. Without a
 * timestamp there is no way to tell how long work sat waiting on a reviewer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('completion_date');

            // "What is waiting on me to review" — the assigner's queue.
            $table->index(['created_by', 'status'], 'tasks_creator_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_creator_status_idx');
            $table->dropColumn('submitted_at');
        });
    }
};
