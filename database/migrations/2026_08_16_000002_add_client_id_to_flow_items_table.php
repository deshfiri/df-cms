<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a flow item belong to a client, which is what turns the generic flow
 * engine into the client pipeline: the stages an admin builds under Workflows
 * become the sequence a specific client moves through.
 *
 * Nullable on purpose — the engine keeps working for standalone internal work
 * that has no client attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('flow_id')
                ->constrained()
                ->nullOnDelete();

            // "What is this client's workflow doing right now" is the query
            // behind the client page and every pipeline view.
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'status']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
