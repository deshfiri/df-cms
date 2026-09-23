<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task can now be assigned to more than one person. Replaces the single
 * tasks.assigned_to column with a task_user pivot, mirroring how clients
 * already work (see client_task). Every existing assignment is carried over
 * before the column is dropped, so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_user', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
        });

        DB::table('tasks')->whereNotNull('assigned_to')->orderBy('id')
            ->each(fn ($task) => DB::table('task_user')->insert([
                'task_id' => $task->id, 'user_id' => $task->assigned_to,
            ]));

        // Four indexes have touched assigned_to over time: the two default-named
        // composite ones from when the column was created, plus two explicitly
        // named ones added later for query-shape tuning (add_performance_indexes)
        // — one of them ('tasks_assigned_status_idx') an exact duplicate shape of
        // the first. All of them have to come off before the column can.
        $existing = collect(Schema::getIndexes('tasks'))->pluck('name');
        $named    = ['tasks_assigned_due_idx', 'tasks_assigned_status_idx'];

        if (DB::getDriverName() === 'sqlite') {
            // SQLite refuses to drop a column still named in a foreign key or
            // an index, even with the pragma off, so all of them come off first —
            // each its own full-table rebuild — before the column's own.
            Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign(['assigned_to']));
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['assigned_to', 'status']));
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['assigned_to', 'completed_at']));
            foreach ($named as $name) {
                if ($existing->contains($name)) {
                    Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex($name));
                }
            }
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('assigned_to'));

            return;
        }

        Schema::table('tasks', function (Blueprint $table) use ($existing, $named) {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropIndex(['assigned_to', 'completed_at']);
            foreach ($named as $name) {
                if ($existing->contains($name)) {
                    $table->dropIndex($name);
                }
            }
            $table->dropColumn('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['assigned_to', 'status']);
            $table->index(['assigned_to', 'completed_at']);
            $table->index(['assigned_to', 'due_date'], 'tasks_assigned_due_idx');
            $table->index(['assigned_to', 'status'], 'tasks_assigned_status_idx');
        });

        // The column only ever held one assignee per task, so the lowest id
        // among however many a task ended up with is what comes back.
        DB::table('task_user')
            ->select('task_id', DB::raw('MIN(user_id) as user_id'))
            ->groupBy('task_id')->orderBy('task_id')
            ->each(fn ($row) => DB::table('tasks')->where('id', $row->task_id)->update(['assigned_to' => $row->user_id]));

        Schema::dropIfExists('task_user');
    }
};
