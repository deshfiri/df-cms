<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One WhatsApp message, in either direction.
 *
 * The unique constraint on `wamid` is the idempotency mechanism, not a nicety:
 * Meta retries webhook deliveries, and the database refusing a second insert is
 * the only guarantee that survives concurrent workers. Application-level "have I
 * seen this?" checks race; a unique index does not.
 *
 * Media is stored the same way as every other upload in this application — bytes
 * on the active storage disk, with the disk recorded per row — because Meta's own
 * media URLs expire within minutes and must never be handed to a browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('whatsapp_conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();

            // Provider id. Nullable only for the moment between creating an
            // outgoing row and Meta accepting it.
            $table->string('wamid', 128)->nullable()->unique();

            $table->string('direction', 10);          // incoming | outgoing
            $table->string('type', 20)->default('text');

            $table->text('body')->nullable();

            // Quoting an earlier message, as WhatsApp itself supports.
            $table->string('context_wamid', 128)->nullable();

            // ── Media ────────────────────────────────────────────────────
            $table->string('media_id', 128)->nullable()->comment('Meta media id, for retrieval');
            $table->string('media_disk', 30)->nullable();
            $table->string('media_path', 512)->nullable();
            $table->string('media_name', 255)->nullable();
            $table->string('media_mime', 127)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();

            // ── Delivery ─────────────────────────────────────────────────
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Which approved template was used, when type = template.
            $table->string('template_name', 191)->nullable();

            // Everything provider-specific that does not deserve a column:
            // location coordinates, interactive payloads, reaction emoji, the
            // original normalised envelope.
            $table->json('metadata')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            // Paging a thread newest-first is the only read pattern that matters.
            $table->index(['whatsapp_conversation_id', 'id'], 'wa_msg_thread_index');
            $table->index('direction');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
