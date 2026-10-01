<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (Manager Oversight & Public Landing Page). `logo`, `description`
 * and `website` already exist on brands (unused since they were added) and
 * are reused as the public-facing content; this is the one genuinely new
 * field — nothing is public until a Manager/Marketing user opts a brand in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
