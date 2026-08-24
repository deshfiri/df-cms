<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a chat attachment's bytes were removed by the retention policy.
 *
 * The message itself always survives — deletion in this module has never been a
 * row removal, because chat monitors are expected to see what was actually said.
 * Pruning follows the same principle one level down: the file goes, the fact
 * that a file was there does not.
 *
 * The name, mime and size columns are deliberately left populated after a purge,
 * so a thread can still say *which* document expired rather than showing a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'attachment_purged_at')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('attachment_purged_at')->nullable()->after('attachment_duration');

            // The pruner scans for old messages that still hold a file; this is
            // the index that keeps that scan off a full table read as the chat
            // history grows.
            $table->index(['attachment_purged_at', 'created_at'], 'messages_attachment_prune_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages', 'attachment_purged_at')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_attachment_prune_index');
            $table->dropColumn('attachment_purged_at');
        });
    }
};
