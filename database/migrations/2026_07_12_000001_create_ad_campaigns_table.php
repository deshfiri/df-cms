<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('platform')->nullable()->comment('Facebook,Instagram,Google,TikTok,YouTube,Other');
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('status')->default('Active')->comment('Active,Paused,Completed,Cancelled');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
