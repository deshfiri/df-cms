<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FlowService::advance() nulls assigned_to the moment an item completes —
 * it's "who currently holds it," not a record of who did the work, and a
 * finished item holds nothing any more. Every place that counted "how many
 * workflow items has this employee completed" filtered on assigned_to, so a
 * genuinely-completed item was invisible to its own completer the instant it
 * finished. This column is set alongside completed_at from here on, and
 * mirrors how the older ClientStageProgress::completed_by already models the
 * same idea for the retired workflow pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * The completing move is the transition with no destination stage —
     * its moved_by is who actually finished it. A separate method so it can
     * be exercised directly against pre-existing rows without also having
     * to recreate the schema change around it.
     */
    public function backfill(): void
    {
        DB::table('flow_items')
            ->where('status', 'Completed')
            ->orderBy('id')
            ->each(function ($item) {
                $movedBy = DB::table('flow_transitions')
                    ->where('flow_item_id', $item->id)
                    ->whereNull('to_stage_id')
                    ->orderByDesc('id')
                    ->value('moved_by');

                if ($movedBy) {
                    DB::table('flow_items')->where('id', $item->id)->update(['completed_by' => $movedBy]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
        });
    }
};
