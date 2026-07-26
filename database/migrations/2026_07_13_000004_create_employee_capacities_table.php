<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_capacities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('working_hours_per_day', 4, 2)->default(8);
            $table->unsignedTinyInteger('working_days_per_week')->default(5);
            $table->unsignedInteger('max_active_tasks')->nullable();
            $table->unsignedInteger('max_workload_points')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_capacities');
    }
};
