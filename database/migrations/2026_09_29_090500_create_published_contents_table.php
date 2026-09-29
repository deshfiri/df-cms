<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only except reviewed_at/reviewed_by — see the SRS integration
 * plan's Fix F. submission_id, facebook_post_url, published_by and
 * published_at are write-once; a correction always means a brand-new row
 * (republishing), never an edit to an existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained('content_item_submissions')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('facebook_post_url', 2048);
            $table->foreignId('published_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('published_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('content_item_id');
            $table->index(['brand_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_contents');
    }
};
