<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer, identified by their WhatsApp ID.
 *
 * One record per person, not per brand: someone who messages two of our brands is
 * the same human, and duplicating them would make it impossible to ever see that.
 * Brand separation lives on the conversation instead, which is what keeps the two
 * threads isolated from each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();

            // Meta's identifier for the person. Unique because it *is* the identity.
            $table->string('wa_id', 32)->unique();

            // Normalised E.164 without the leading '+', kept alongside wa_id because
            // the two can differ (some countries' wa_id drops a trunk digit).
            $table->string('phone', 32)->nullable();

            // What they call themselves on WhatsApp, versus what we have chosen to
            // call them here. Never let their profile name overwrite ours.
            $table->string('profile_name', 191)->nullable();
            $table->string('name', 191)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};
