<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_satisfaction_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type')->comment('SupportTicket,WorkflowDepartment');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('client_portal_users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_excluded')->default(false);
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('excluded_reason')->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_satisfaction_ratings');
    }
};
