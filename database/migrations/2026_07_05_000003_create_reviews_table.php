<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('review')->comment('review,report');
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_department', 60)->nullable();
            $table->string('title');
            $table->text('message');
            $table->boolean('is_anonymous')->default(false);
            // Deliberately nullable with no fallback: an anonymous review never
            // records who posted it anywhere, so there is nothing to reveal —
            // not even to Super Admin. Do not backfill this for anonymous rows.
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
