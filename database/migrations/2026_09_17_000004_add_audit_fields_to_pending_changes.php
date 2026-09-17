<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The change queue becomes the permanent audit trail for payment corrections.
 *
 *  - reason      why the change was asked for (required for payments)
 *  - applied_at  when it actually took effect — for an approved request, the
 *                approval; for an approver's own edit, the edit itself
 *
 * A new status, 'applied', records an edit made directly by someone who may
 * approve, so every change to a payment has a row whether or not it waited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_changes', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('new_values');
            $table->timestamp('applied_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pending_changes', function (Blueprint $table) {
            $table->dropColumn(['reason', 'applied_at']);
        });
    }
};
