<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('dfid_number')->unique()->comment('Unique DFID identifier');
            $table->string('client_name');
            $table->string('brand_name');
            $table->string('website')->nullable();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->date('joining_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_status')->default('Running')
                ->comment('Running,Warning,Completed,Hold,Cancelled');
            $table->text('remarks')->nullable();
            $table->string('doc_status')->nullable()->comment('DONE or null');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_status', 'category_id']);
            $table->index('joining_date');
            $table->index('dfid_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
