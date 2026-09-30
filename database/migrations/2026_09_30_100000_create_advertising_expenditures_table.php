<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing's expenditure ledger against a brand's advertising budget
 * (Brand::advertisingBudget(), a Social Media Ads invoice — Phase 0).
 * ad_campaign_id is a reporting link only; this table is never read by, or
 * written to, AdCampaign.budget_remaining or the Meta sync — see the SRS
 * integration plan's decision 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertising_expenditures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('reporting_date');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            // Client-generated per submit action — a retried request (network
            // timeout, double-click) with the same key returns the original
            // row instead of inserting a second one. Distinct from the soft
            // duplicate guard, which catches a human plausibly re-entering
            // the same thing under a different key.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['brand_id', 'reporting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertising_expenditures');
    }
};
