<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production-readiness hardening: a deleted expenditure's full row (amount,
 * dates, who recorded it, idempotency_key) is now recoverable via
 * withTrashed() for audit purposes — the PendingChange/ActivityLog snapshot
 * only ever covered CORRECTABLE_FIELDS, not recorded_by/created_at/id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertising_expenditures', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('advertising_expenditures', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
