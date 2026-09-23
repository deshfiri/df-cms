<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing, optional per-employee goal — "N tasks a day" — set by whoever
 * holds 'manage performance' (Settings → Performance Configuration). No row
 * for a user means no daily target: the KPI it feeds is simply left out of
 * their score, same as an employee with no sales target or no client ratings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('target_tasks_per_day');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_targets');
    }
};
