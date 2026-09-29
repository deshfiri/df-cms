<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One checklist per brand, auto-created once the brand has a paid
 * advertising-budget invoice and a content-charge invoice (see
 * App\Observers\InvoiceObserver). Not a fixed shape — content_items under
 * it can be any count, in any category. on_hold_at/on_hold_reason pause new
 * work without ever deleting existing content (see the SRS integration
 * plan's Fix E).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained('brands')->cascadeOnDelete();
            $table->timestamp('on_hold_at')->nullable();
            $table->string('on_hold_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_checklists');
    }
};
