<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categorised billing.
 *
 * A client is rarely billed for one thing. "Social Media Ads — ৳20,000" and
 * "Website — ৳50,000" are separate charges, each paid down on its own schedule,
 * so a charge (an invoice) and every payment against it now carry a category.
 *
 * Both columns are nullable: every invoice and payment recorded before this
 * simply reads as uncategorised, and nothing that already sums payments by
 * status changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('payment_category_id')->nullable()->after('client_id')
                ->constrained('payment_categories')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_category_id')->nullable()->after('invoice_id')
                ->constrained('payment_categories')->nullOnDelete();
        });

        // Sensible starting set; admins rename, reorder or add from Settings.
        $now = now();
        $defaults = ['Social Media Ads', 'Website Development', 'Branding & Design', 'SEO', 'Product Listing', 'Other'];
        DB::table('payment_categories')->insert(array_map(fn ($name, $i) => [
            'name'       => $name,
            'is_active'  => true,
            'sort_order' => ($i + 1) * 10,
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaults, array_keys($defaults)));
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_category_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_category_id');
        });

        Schema::dropIfExists('payment_categories');
    }
};
