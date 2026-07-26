<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('period', 7)->comment('YYYY-MM');
            $table->decimal('task_completion_score', 5, 2)->nullable();
            $table->decimal('on_time_score', 5, 2)->nullable();
            $table->decimal('revision_score', 5, 2)->nullable();
            $table->decimal('sales_score', 5, 2)->nullable();
            $table->decimal('satisfaction_score', 5, 2)->nullable();
            $table->json('weights_used');
            $table->json('component_details');
            $table->decimal('final_score', 5, 2);
            $table->string('performance_level');
            $table->unsignedInteger('rank_department')->nullable();
            $table->unsignedInteger('rank_company')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_performance_snapshots');
    }
};
