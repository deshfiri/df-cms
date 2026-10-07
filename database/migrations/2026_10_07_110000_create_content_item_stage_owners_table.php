<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage-aware, append-only ownership of a workflow stage.
 *
 * One ContentItem has several owners over its life: a maker, the Marketing user
 * who handles the pre-publish check of a version, the SMM user who publishes it,
 * and the Marketing reviewer of the publication. Each of those is a stage, and
 * each stage belongs to one exact version (submission, publication or revision).
 *
 * active_ref is "stage_ref" while the owner is live, and NULL once that owner
 * is released or reassigned. The unique index on active_ref means only one
 * live owner can ever exist per stage. That is the atomic guarantee that two
 * simultaneous claims cannot both succeed. Released rows stay, so the history
 * of claims, assignments and reassignments is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_item_stage_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->restrictOnDelete();
            $table->string('stage', 20);
            $table->string('stage_ref', 64);
            $table->string('active_ref', 64)->nullable();
            $table->foreignId('submission_id')->nullable()->constrained('content_item_submissions')->restrictOnDelete();
            $table->foreignId('publication_id')->nullable()->constrained('published_contents')->restrictOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained('content_item_revisions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('source', 20);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acquired_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('active_ref', 'stage_owners_active_unique');
            $table->index(['content_item_id', 'stage'], 'stage_owners_item_stage_idx');
            $table->index(['user_id', 'acquired_at'], 'stage_owners_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_stage_owners');
    }
};
