<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->decimal('total_payable', 12, 2);
            $table->date('due_date')->nullable();
            $table->string('status')->default('Unpaid')
                ->comment('Unpaid,Partially Paid,Paid,Overdue,Refunded,Non-Refundable,Cancelled');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->date('issued_date');
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
