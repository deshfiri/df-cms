<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which disk each stored file actually lives on.
 *
 * The portal and client-document tables have carried a `disk` column since they
 * were written; these four predate it and assumed 'local'. Without the column,
 * connecting a CDN would strand every existing attachment — the app would look
 * for yesterday's uploads on today's provider.
 *
 * Defaulting to 'local' is what makes the backfill unnecessary: every row that
 * already exists was, by definition, written to the local disk.
 */
return new class extends Migration
{
    /** table => [column name, the column it should sit after] */
    private const TARGETS = [
        'documents'              => ['disk', 'file_path'],
        'task_attachments'       => ['disk', 'file_path'],
        'flow_item_attachments'  => ['disk', 'file_path'],
        'messages'               => ['attachment_disk', 'attachment_path'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$column, $after]) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $after) {
                $blueprint->string($column, 30)->default('local')->after($after);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [$column, $after]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropColumn($column);
            });
        }
    }
};
