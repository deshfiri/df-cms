<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing's exact-version "pre-publish check passed, hand this over to
 * SMM" gate — a new append-only record rather than another ContentItem
 * status, so approval stays auditable (who, when, for which exact
 * submission) without overloading content_items.status with a meaning it
 * was never designed to carry. unique('submission_id') is both the
 * audit-trail shape (one approval per submission, ever) and the
 * concurrency backstop: two near-simultaneous "Approve" clicks can only
 * ever produce one row — the loser's insert fails the constraint rather
 * than creating a duplicate handover.
 *
 * Deliberately submission-scoped, not item-scoped: a later resubmission is
 * a brand-new content_item_submissions row with no approval of its own, so
 * this table can never let an old version's approval reach a newer one
 * (see ContentItemService::approveForHandover()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_item_submission_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('submission_id')->unique()->constrained('content_item_submissions')->cascadeOnDelete();
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['content_item_id', 'approved_at'], 'content_item_approvals_item_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_submission_approvals');
    }
};
