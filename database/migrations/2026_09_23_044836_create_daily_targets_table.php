<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing, optional per-employee, per-scope goal — "N tasks a day", "N
 * workflow items a day" — set by whoever holds 'manage performance'
 * (Performance → Configuration → Daily Targets). A user can have a target on
 * any subset of the available scopes (App\Models\DailyTarget::$scopes); no
 * rows for a user means no daily target at all, and the KPI it feeds is
 * simply left out of their score, same as an employee with no sales target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 20);
            $table->unsignedSmallInteger('target_quantity');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_targets');
    }
};
