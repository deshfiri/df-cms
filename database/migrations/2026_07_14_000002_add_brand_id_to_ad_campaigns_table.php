<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('client_id')->constrained('brands')->nullOnDelete();
            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'status']);
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
