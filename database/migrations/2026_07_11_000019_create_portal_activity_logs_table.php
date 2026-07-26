<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_portal_user_id')->constrained('client_portal_users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('module');
            $table->string('action');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['client_portal_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_activity_logs');
    }
};
