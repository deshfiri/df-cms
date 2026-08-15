<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic, reusable workflow engine (distinct from the client pipeline in
 * workflow_stages). A Flow has ordered FlowStages; each stage has assigned
 * users; FlowItems move serially stage→stage and every move is a FlowTransition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['flow_id', 'position']);
        });

        Schema::create('flow_stage_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_stage_id')->constrained('flow_stages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flow_stage_id', 'user_id']);
        });

        Schema::create('flow_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            // null once the item has passed the final stage (Completed).
            $table->foreignId('current_stage_id')->nullable()->constrained('flow_stages')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('Open'); // Open | Completed | Cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['current_stage_id', 'status']);
            $table->index(['flow_id', 'status']);
        });

        Schema::create('flow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_item_id')->constrained('flow_items')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('flow_stages')->nullOnDelete(); // null = item creation
            $table->foreignId('to_stage_id')->nullable()->constrained('flow_stages')->nullOnDelete();   // null = completed
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('flow_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_transitions');
        Schema::dropIfExists('flow_items');
        Schema::dropIfExists('flow_stage_user');
        Schema::dropIfExists('flow_stages');
        Schema::dropIfExists('flows');
    }
};
