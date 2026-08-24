<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a message quote an earlier one in the same conversation.
 *
 * nullOnDelete rather than cascade: if a quoted message ever were removed for
 * real, the reply is still a message someone sent and must survive — it simply
 * loses its quote. (Retraction is a flag, not a delete, so in practice the row
 * stays and the quote renders as "This message was deleted".)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'reply_to_id')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')
                ->nullable()
                ->after('sender_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages', 'reply_to_id')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
