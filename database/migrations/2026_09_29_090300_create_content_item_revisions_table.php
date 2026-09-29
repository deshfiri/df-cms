<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only, mirrors task_revisions exactly — see TaskRevision. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_item_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->string('previous_status');
            $table->timestamp('created_at')->useCurrent();

            $table->index('content_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_revisions');
    }
};
