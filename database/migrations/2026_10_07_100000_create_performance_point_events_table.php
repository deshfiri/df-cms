<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of workflow Performance Points. One row is one award to
 * one exact user for one exact business event.
 *
 * unique(event_type, source_type, source_id) is the idempotency guarantee: the
 * same approval, review or conversation decision can only ever award once,
 * however many times the request is repeated or raced. Rows are never updated
 * or deleted. Foreign keys restrict deletion, so removing an employee account
 * can't erase the performance history it earned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_point_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->unsignedSmallInteger('points');
            $table->foreignId('awarded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('awarded_at');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_type', 'source_type', 'source_id'], 'perf_points_source_unique');
            $table->index(['user_id', 'awarded_at'], 'perf_points_user_awarded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_point_events');
    }
};
