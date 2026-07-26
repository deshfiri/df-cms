<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_client_visible')->default(true)->after('requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_client_visible']);
        });
    }
};
