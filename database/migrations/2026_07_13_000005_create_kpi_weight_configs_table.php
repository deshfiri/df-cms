<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_weight_configs', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type')->comment('global,department,employee');
            $table->string('scope_value')->nullable()->comment('role name for department, user id for employee, null for global');
            $table->unsignedTinyInteger('task_completion_weight');
            $table->unsignedTinyInteger('on_time_weight');
            $table->unsignedTinyInteger('revision_weight');
            $table->unsignedTinyInteger('sales_weight');
            $table->unsignedTinyInteger('satisfaction_weight');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_weight_configs');
    }
};
