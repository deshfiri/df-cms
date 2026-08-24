<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message template as Meta holds it.
 *
 * This table is a local mirror, never a source of truth: templates are authored
 * and approved on Meta's side, and `status` here only reflects what the last sync
 * reported. Nothing in the application may treat a row here as permission to send
 * — the send path re-checks status at the moment of use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();

            $table->string('template_id', 64)->nullable()->comment('Meta template id');
            $table->string('name', 191);
            $table->string('language', 20);
            $table->string('category', 40)->nullable();
            $table->string('status', 20)->default('PENDING');

            // Header/body/footer/buttons, with their placeholders, exactly as Meta
            // returns them — the send path reads this to know what parameters a
            // template requires.
            $table->json('components')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // Meta's own identity for a template is name + language per account.
            $table->unique(['whatsapp_account_id', 'name', 'language'], 'wa_tpl_identity_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
