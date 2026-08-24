<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One customer's thread with one brand number.
 *
 * brand_id is denormalised from the account on purpose: every inbox query filters
 * and authorises by brand, and joining through whatsapp_accounts on the hot path
 * for hundreds of brands is exactly the N+1-shaped cost the spec warns about.
 *
 * Deliberately not the internal chat `conversations` table — that one is a 1:1
 * join of two staff users and has no room for a brand, a status, an assignment,
 * or an external participant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();

            // One responsible agent, matching how tasks and clients are assigned
            // elsewhere in this application. Null means nobody has picked it up.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->string('status', 20)->default('open');
            $table->string('priority', 20)->default('normal');

            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->unsignedInteger('unread_count')->default(0);

            /*
             * When the customer last wrote to us.
             *
             * Meta only permits free-form replies inside a service window that
             * opens on the customer's message; outside it, only an approved
             * template may be sent. Storing the timestamp lets the window be
             * evaluated without another API call, and without hard-coding a
             * policy duration into the schema.
             */
            $table->timestamp('last_customer_message_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // A customer has exactly one thread per brand number.
            $table->unique(['whatsapp_account_id', 'whatsapp_contact_id'], 'wa_conv_account_contact_unique');

            $table->index('brand_id');
            $table->index('assigned_user_id');
            $table->index('status');
            $table->index('last_message_at');
            // The inbox's default ordering, scoped by brand.
            $table->index(['brand_id', 'status', 'last_message_at'], 'wa_conv_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
