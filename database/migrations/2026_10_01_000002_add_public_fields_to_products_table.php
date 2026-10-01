<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (Manager Oversight & Public Landing Page). Unlike brands, products
 * have no description/image column at all yet — both are new, alongside the
 * same opt-in `is_public` flag used on brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('image')->nullable()->after('description');
            $table->boolean('is_public')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['description', 'image', 'is_public']);
        });
    }
};
