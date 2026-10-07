<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An SMM user's record of one client conversation for a Brand/Product, with the
 * screenshot as evidence. Marketing verifies it: approve as a Potential Client,
 * or reject. The submitter owns their own record, and nobody else can change it.
 *
 * A reference is an internal conversation identifier, never a client's personal
 * details, so the table has no phone or contact column by design.
 *
 * idempotency_key is unique per submitter, so a double-submitted form returns the
 * record it already made rather than a second one. The review fields are
 * historical and nullable, and deleting an account can't erase them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smm_client_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('reference', 120);
            $table->string('note', 500)->nullable();
            $table->string('evidence_disk', 32);
            $table->string('evidence_path', 255);
            $table->string('evidence_mime', 64)->nullable();
            $table->unsignedInteger('evidence_size')->default(0);
            $table->string('idempotency_key', 64);
            $table->string('review_status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['submitted_by', 'idempotency_key'], 'smm_conv_idempotency_unique');
            $table->index(['review_status', 'submitted_at'], 'smm_conv_status_idx');
            $table->index(['brand_id', 'submitted_at'], 'smm_conv_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_client_conversations');
    }
};
