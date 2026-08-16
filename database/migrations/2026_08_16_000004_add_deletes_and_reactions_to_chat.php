<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message deletion and reactions.
 *
 * Deletion is deliberately a flag, not a row removal and not Laravel's
 * SoftDeletes trait: participants must see "this message was deleted" while
 * chat monitors still see what was actually said. A global soft-delete scope
 * would hide the row from the monitor too, which is the opposite of the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('read_at');
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            // One of each emoji per person per message; reacting again removes it.
            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deleted_at');
        });
    }
};
