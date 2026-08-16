<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 20)->default('ringing');
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_reason', 100)->nullable();

            $table->timestamps();

            // "Is this person already on a call" runs inside a locked transaction
            // on every call attempt, from both directions.
            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
            // The reconciler sweeps stale ringing/accepted rows by age.
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
