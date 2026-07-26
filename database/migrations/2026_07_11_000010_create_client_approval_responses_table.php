<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_approval_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_approval_request_id')->constrained('client_approval_requests')->cascadeOnDelete();
            $table->foreignId('responded_by')->constrained('client_portal_users')->restrictOnDelete();

            $table->string('response')->comment('Approved,Revision Requested,Rejected');
            $table->text('comment')->nullable();
            $table->unsignedSmallInteger('version');

            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->timestamps();

            $table->index('client_approval_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_approval_responses');
    }
};
