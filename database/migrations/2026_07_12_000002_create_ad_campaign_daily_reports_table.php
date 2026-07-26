<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaign_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->date('report_date');
            $table->decimal('spend', 12, 2)->default(0);
            $table->decimal('sales', 12, 2)->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_daily_reports');
    }
};
