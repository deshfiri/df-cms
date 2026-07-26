<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('client_portal_users')->restrictOnDelete();

            $table->string('category')
                ->comment('General,Website,Content,Marketing,Payment,Product,Warehouse,Fulfillment,Technical,Other');
            $table->string('priority')->default('Medium')->comment('Low,Medium,High,Urgent');
            $table->string('subject', 200);
            $table->text('message');

            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('status')->default('Open')
                ->comment('Open,Assigned,In Progress,Waiting for Client,Resolved,Closed');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
