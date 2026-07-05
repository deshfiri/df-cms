<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority')->default('Medium')
                ->comment('Low,Medium,High,Urgent');
            $table->string('status')->default('Pending')
                ->comment('Pending,In Progress,On Hold,Completed,Cancelled,Overdue');
            $table->string('type')->default('Other')
                ->comment('Call,Meeting,Email,Follow Up,Visit,Proposal,Invoice,Support,Other');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completion_date')->nullable();
            $table->dateTime('reminder_at')->nullable();
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->decimal('actual_hours', 6, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index(['client_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index('due_date');
            $table->index('reminder_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
