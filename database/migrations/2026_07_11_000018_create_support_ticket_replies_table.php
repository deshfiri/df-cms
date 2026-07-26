<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type')->comment('staff,client_portal');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_portal_user_id')->nullable()->constrained('client_portal_users')->nullOnDelete();

            $table->text('message');
            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->boolean('is_internal_note')->default(false);

            $table->timestamps();

            $table->index('support_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
    }
};
