<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LandingController's public queries (Brand::public()->active(), and
 * $brand->products()->public()->active()) had no supporting index — is_public
 * was added without one in the Phase 4 migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->index(['is_public', 'is_active'], 'brands_public_active_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['brand_id', 'is_public', 'is_active'], 'products_brand_public_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex('brands_public_active_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_brand_public_active_index');
        });
    }
};
