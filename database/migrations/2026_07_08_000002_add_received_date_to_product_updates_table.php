<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_updates', function (Blueprint $table) {
            $table->date('received_date')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('product_updates', function (Blueprint $table) {
            $table->dropColumn('received_date');
        });
    }
};
