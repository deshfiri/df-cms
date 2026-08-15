<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claim/pick-up ownership: which user has taken the item at its current stage.
 * Null = unclaimed (visible to the whole stage team); reset to null on every
 * stage change so the next team must claim it themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('current_stage_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
