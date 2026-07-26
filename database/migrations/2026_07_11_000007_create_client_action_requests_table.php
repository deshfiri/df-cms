<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_action_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('workflow_stages')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description');
            $table->boolean('required_attachment')->default(false);
            $table->string('priority')->default('Medium')->comment('Low,Medium,High,Urgent');
            $table->date('due_date')->nullable();
            $table->string('status')->default('Pending')
                ->comment('Pending,Submitted,Under Review,Approved,Need Revision,Rejected,Completed');
            $table->text('team_feedback')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_action_requests');
    }
};
