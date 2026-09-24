<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shared task now needs every current assignee to submit their own part
 * before the task as a whole reaches Submitted — see TaskService::
 * submitForReview(). This is what tracks who already has.
 *
 * A task already fully Submitted (or further along) when this runs predates
 * per-assignee tracking: everyone on it is backfilled as having submitted,
 * so it is not mistaken for a stalled partial submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_user', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
        });

        // Per task, not a single joined UPDATE: SQLite (the test suite's
        // driver) has no portable multi-table UPDATE syntax, and this only
        // ever runs once per install regardless.
        DB::table('tasks')
            ->whereIn('status', ['Submitted', 'Completed'])
            ->orderBy('id')
            ->select('id', 'submitted_at', 'updated_at')
            ->each(function ($task) {
                DB::table('task_user')
                    ->where('task_id', $task->id)
                    ->update(['submitted_at' => $task->submitted_at ?? $task->updated_at]);
            });
    }

    public function down(): void
    {
        Schema::table('task_user', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
