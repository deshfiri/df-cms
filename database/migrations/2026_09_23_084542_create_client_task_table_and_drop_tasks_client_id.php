<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task can now be linked to more than one client. Replaces the single
 * tasks.client_id column with a client_task pivot, mirroring how labels
 * already work (see label_task). Every existing client association is
 * carried over before the column is dropped, so nothing is lost.
 *
 * Deleting a client used to cascade-delete every task pointed at it; with a
 * task now able to have several clients, that would wrongly delete a task
 * over just one of its clients. Deleting a client now only removes that one
 * pivot row, the same as it already does for a task's labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['client_id', 'task_id']);
        });

        DB::table('tasks')->whereNotNull('client_id')->orderBy('id')
            ->each(fn ($task) => DB::table('client_task')->insert([
                'client_id' => $task->client_id, 'task_id' => $task->id,
            ]));

        if (DB::getDriverName() === 'sqlite') {
            // SQLite refuses to drop a column still named in a foreign key or
            // an index, even with the pragma off, so both come off first —
            // each its own full-table rebuild — before the column's own.
            Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign(['client_id']));
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['client_id', 'status']));
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('client_id'));

            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id', 'status']);
            $table->dropColumn('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->index(['client_id', 'status']);
        });

        // The column only ever held one client per task, so the lowest id
        // among however many a task ended up with is what comes back.
        DB::table('client_task')
            ->select('task_id', DB::raw('MIN(client_id) as client_id'))
            ->groupBy('task_id')->orderBy('task_id')
            ->each(fn ($row) => DB::table('tasks')->where('id', $row->task_id)->update(['client_id' => $row->client_id]));

        Schema::dropIfExists('client_task');
    }
};
