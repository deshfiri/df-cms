<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->unique()->after('name');
            $table->string('department', 60)->nullable()->after('code');
            $table->boolean('requires_approval')->default(true)->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn(['code', 'department', 'requires_approval']);
        });
    }
};
