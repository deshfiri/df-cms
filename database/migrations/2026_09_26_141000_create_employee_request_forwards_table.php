<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per hop when a recipient hands their copy of a request to someone
 * else instead of answering it — "Ahsan -> Moulin -> Salman" is reconstructed
 * by walking these rows backward from whoever currently holds it (see
 * EmployeeRequest::chainFor()), the same one-hop-per-row convention
 * client_ownership_transfers already uses for Client ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_request_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['employee_request_id', 'created_at']);
            $table->index('to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_request_forwards');
    }
};
