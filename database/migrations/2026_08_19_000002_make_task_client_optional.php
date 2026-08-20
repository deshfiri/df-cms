<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Not every task belongs to a client.
 *
 * Tasks were built as client work, so client_id was mandatory. But plenty of
 * real work is internal — "write the onboarding doc", "fix the printer",
 * delegating something to a junior — and forcing an unrelated client onto those
 * records made the client's own task history wrong as well as being a nuisance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot drop a foreign key; Laravel rebuilds the table for
            // a change(), which carries the constraint over.
            Schema::table('tasks', fn (Blueprint $table) => $table->foreignId('client_id')->nullable()->change());

            return;
        }

        // MySQL will not widen a column that a foreign key points at, so the
        // constraint comes off and goes back on around the change.
        Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        Schema::table('tasks', fn (Blueprint $table) => $table->foreignId('client_id')->nullable()->change());
        Schema::table('tasks', fn (Blueprint $table) => $table
            ->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete());
    }

    public function down(): void
    {
        // Tasks with no client cannot exist under the old rule; they would block
        // the column change, so they go first.
        DB::table('tasks')->whereNull('client_id')->delete();

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('tasks', fn (Blueprint $table) => $table->foreignId('client_id')->nullable(false)->change());

            return;
        }

        Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        Schema::table('tasks', fn (Blueprint $table) => $table->foreignId('client_id')->nullable(false)->change());
        Schema::table('tasks', fn (Blueprint $table) => $table
            ->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete());
    }
};
