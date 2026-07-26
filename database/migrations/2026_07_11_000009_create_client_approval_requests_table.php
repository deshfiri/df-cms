<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('workflow_stages')->restrictOnDelete();

            $table->string('approval_type')
                ->comment('Logo,Brand,Product,Content,Video,Website,Supplier,Quotation,Agreement,Campaign');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('version')->default(1);

            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('external_preview_url')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->date('deadline')->nullable();
            $table->boolean('allow_reject')->default(true);
            $table->string('status')->default('Pending')
                ->comment('Pending,Approved,Revision Requested,Rejected,Expired');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_approval_requests');
    }
};
