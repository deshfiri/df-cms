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
        Schema::create('client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();

            // File info
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('extension', 20);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            // Versioning
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('client_documents')->nullOnDelete();

            // Metadata
            $table->date('expiry_date')->nullable();
            $table->json('tags')->nullable();

            // Download tracking
            $table->unsignedInteger('download_count')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'document_type_id']);
            $table->index(['client_id', 'version']);
        });

        Schema::create('document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('client_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('downloaded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
        Schema::dropIfExists('client_documents');
    }
};
