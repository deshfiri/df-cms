<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('workflow_stages')->restrictOnDelete();
            $table->string('department', 60)->nullable();

            $table->string('title', 200);
            $table->text('description');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->text('next_action')->nullable();
            $table->date('expected_completion_date')->nullable();
            $table->string('video_url')->nullable();
            $table->string('external_link')->nullable();

            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->boolean('is_client_visible')->default(true);
            $table->timestamp('visible_to_client_at')->nullable();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'is_client_visible', 'created_at'], 'cpu_client_visible_created_idx');
            $table->index(['client_id', 'stage_id']);
            $table->index(['client_id', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_updates');
    }
};
