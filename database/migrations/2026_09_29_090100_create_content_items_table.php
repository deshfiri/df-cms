<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One checklist line item — uncapped per category, optionally tied to a
 * Product. Everything downstream (submissions, collections, publishes,
 * reviews) lives in its own append-only table keyed off this row, so
 * status here is always "what's true right now," never history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('brand_checklists')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('category')->comment('raw_content,poster,advertising_content');
            $table->string('status')->default('pending')
                ->comment('pending,in_progress,available,collected,published,needs_revision');
            $table->string('title', 200);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'category']);
            $table->index(['checklist_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
