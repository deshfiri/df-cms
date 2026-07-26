<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('name');
            $table->string('phone')->nullable()->after('designation');
            $table->string('whatsapp')->nullable()->after('phone');
            $table->string('office_hours')->nullable()->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['designation', 'phone', 'whatsapp', 'office_hours']);
        });
    }
};
