<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One connected WhatsApp Business phone number.
 *
 * Anchored to the existing Brand rather than a WhatsApp-specific brand entity, so
 * a number inherits the brand structure the rest of the application already uses.
 * A brand may hold any number of these — the relationship is deliberately hasMany
 * so that "one number per brand" is never baked in.
 *
 * Soft-deleted, and `status` carries disabling separately: removing a number must
 * never take its conversation history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();

            // Meta identifiers. phone_number_id is what inbound webhooks carry, so
            // it is the lookup key on the hot path and must be unique across the
            // installation — the same number cannot belong to two brands.
            $table->string('waba_id', 64)->comment('WhatsApp Business Account ID');
            $table->string('phone_number_id', 64)->unique();
            $table->string('display_phone_number', 32)->nullable();
            $table->string('verified_name', 191)->nullable();

            // Encrypted at rest, decrypted only inside the service layer.
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->string('status', 20)->default('pending');
            $table->boolean('webhook_subscribed')->default(false);
            $table->timestamp('last_webhook_at')->nullable();

            // Quality rating, messaging limit, last error — provider detail that
            // should not each become a column.
            $table->json('metadata')->nullable();

            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('brand_id');
            $table->index('waba_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
