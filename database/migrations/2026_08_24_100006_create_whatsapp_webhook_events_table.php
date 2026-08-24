<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook delivery Meta makes to us.
 *
 * Two jobs. First, the unique `signature_hash` turns Meta's at-least-once delivery
 * into at-most-once processing: a retry loses the insert race and is dropped
 * before any message is written. Second, it is the only forensic record of what
 * arrived — when an agent swears a message never appeared, this says whether Meta
 * ever sent it.
 *
 * Deliberately stores the payload. It contains customer message text, so it is
 * pruned on a schedule rather than kept forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();

            // sha256 of the raw body. Meta sends no delivery id, and the same
            // payload replayed is by definition the same event.
            $table->string('signature_hash', 64)->unique();

            $table->string('phone_number_id', 64)->nullable();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();

            $table->string('event_type', 40)->nullable();   // message | status | unknown
            $table->string('status', 20)->default('received'); // received | processed | ignored | failed

            $table->json('payload');
            $table->text('error')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('phone_number_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
