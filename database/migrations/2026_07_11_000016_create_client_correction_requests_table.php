<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('client_portal_users')->restrictOnDelete();

            $table->string('category')
                ->comment('Personal,Company,Brand,Contact,Billing,Delivery,Business,Product');
            $table->string('field_label', 150);
            $table->text('current_value')->nullable();
            $table->text('requested_value');
            $table->text('reason')->nullable();

            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('status')->default('Pending')
                ->comment('Pending,Approved,Rejected,Need More Information');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_correction_requests');
    }
};
