<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group chats.
 *
 * A group is a conversation like any other — messages, attachments, reactions,
 * replies and the realtime channel all hang off `conversation_id` already — so
 * it is added to the existing table rather than built alongside it.
 *
 * What a group needs that a pair does not:
 *  - a type, a name and who started it;
 *  - a member list, with each member's role;
 *  - read state per member. `messages.read_at` is one timestamp per message,
 *    which only means something between two people; in a group each member
 *    keeps their own "read up to here" marker instead.
 *
 * The pair columns become nullable, because a group has no pair. Their unique
 * index still guards direct conversations: rows with NULLs never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type', 10)->default('direct')->after('id');
            $table->string('name', 100)->nullable()->after('type');
            $table->foreignId('created_by')->nullable()->after('name')->constrained('users')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_one_id')->nullable()->change();
            $table->unsignedBigInteger('user_two_id')->nullable()->change();
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // owner | admin | member
            $table->string('role', 10)->default('member');
            // Everything at or below this id has been seen by this member.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_user');

        // A group has no pair to restore, so groups cannot survive the rollback.
        DB::table('conversations')->where('type', 'group')->delete();

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'name']);
        });
    }
};
