<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proof_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('client_portal_users')->restrictOnDelete();

            $table->decimal('amount_claimed', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->date('payment_date')->nullable();

            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('status')->default('Pending')->comment('Pending,Verified,Rejected');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->foreignId('resulting_payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proof_submissions');
    }
};
