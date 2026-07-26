<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_stage_progress', function (Blueprint $table) {
            $table->text('client_update_text')->nullable()->after('remarks');
            $table->string('next_step')->nullable()->after('client_update_text');
            $table->boolean('client_action_required')->default(false)->after('next_step');
            $table->boolean('client_approval_required')->default(false)->after('client_action_required');
        });
    }

    public function down(): void
    {
        Schema::table('client_stage_progress', function (Blueprint $table) {
            $table->dropColumn(['client_update_text', 'next_step', 'client_action_required', 'client_approval_required']);
        });
    }
};
