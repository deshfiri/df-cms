<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Brand Content & Advertising system's content-charge invoices need a
 * category distinct from "Social Media Ads" (the advertising-budget one,
 * already seeded) — see the Brand Content & Advertising SRS integration plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payment_categories')->where('name', 'Content Production')->exists()) {
            return;
        }

        $maxSort = (int) DB::table('payment_categories')->max('sort_order');

        DB::table('payment_categories')->insert([
            'name'       => 'Content Production',
            'is_active'  => true,
            'sort_order' => $maxSort + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $category = DB::table('payment_categories')->where('name', 'Content Production')->first();
        if ($category && !DB::table('invoices')->where('payment_category_id', $category->id)->exists()
            && !DB::table('payments')->where('payment_category_id', $category->id)->exists()) {
            DB::table('payment_categories')->where('id', $category->id)->delete();
        }
    }
};
