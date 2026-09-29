<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only — SMM's explicit claim of one specific submission. Never
 * updated after insert, so which submission was collected, by whom, and
 * when stays on record even after a later revision supersedes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_item_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained('content_item_submissions')->cascadeOnDelete();
            $table->foreignId('collected_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('collected_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index('content_item_id');
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_collections');
    }
};
