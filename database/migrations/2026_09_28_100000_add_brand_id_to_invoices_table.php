<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A charge can now belong to a specific brand under its client, not just the
 * client as a whole — needed so a client with several brands gets a
 * strictly per-brand advertising budget rather than one shared pool.
 * Nullable: every existing charge simply stays client-level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('client_id')
                ->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
