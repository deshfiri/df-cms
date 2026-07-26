<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason_category')->comment('Employee Mistake,Client Requested,Scope Change,Management Requested');
            $table->text('note')->nullable();
            $table->string('previous_status');
            $table->timestamp('created_at')->useCurrent();

            $table->index('task_id');
            $table->index('reason_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_revisions');
    }
};
