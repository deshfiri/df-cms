<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaign_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('previous_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['ad_campaign_id', 'created_at']);
            $table->index('new_assignee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_assignments');
    }
};
