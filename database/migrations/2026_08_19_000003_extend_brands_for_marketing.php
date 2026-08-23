<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Brands already existed as a label on ad campaigns. They now become the unit a
 * platform integration hangs off, so they need an identity of their own.
 *
 * Purely additive: every existing brand keeps its id, name and campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->string('logo')->nullable()->after('slug');
            $table->string('website')->nullable()->after('logo');
            $table->text('description')->nullable()->after('website');
            // Deactivating a brand stops it being synced without deleting it.
            $table->boolean('is_active')->default(true)->after('description');

            $table->index(['client_id', 'is_active']);
        });

        // Backfill slugs for brands that pre-date this column.
        foreach (DB::table('brands')->select('id', 'name')->get() as $brand) {
            DB::table('brands')->where('id', $brand->id)->update([
                'slug' => Str::slug($brand->name) . '-' . $brand->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'is_active']);
            $table->dropColumn(['slug', 'logo', 'website', 'description', 'is_active']);
        });
    }
};
