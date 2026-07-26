<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password');
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('Active')->comment('Active,Suspended,Disabled,Completed');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_users');
    }
};
