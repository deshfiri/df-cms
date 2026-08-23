<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Synced advertising hierarchy: ad account → campaign → ad set → ad → insights.
 *
 * Deliberately NOT the existing `ad_campaigns` table. That one is the manual
 * system — hand-entered campaigns and daily figures — and it stays exactly as
 * it is, still readable and editable. Its shape (no external id, no ad account,
 * no objective) cannot represent a platform campaign without lossy contortions,
 * and overwriting it during a sync would destroy hand-entered history.
 *
 * Common metrics are normalised into columns so the dashboard is
 * platform-agnostic; anything platform-specific goes in `metadata`.
 *
 * Every table carries brand_id as well as its parent key. It is denormalised on
 * purpose: nearly every query filters by brand, and joining four levels up to
 * find it would be the dominant cost on a table with millions of insight rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('brand_integration_id')->constrained('brand_integrations')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('timezone')->nullable();
            $table->string('status', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id'], 'ad_account_external_unique');
            $table->index(['brand_id', 'platform']);
        });

        Schema::create('platform_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('platform_ad_account_id')->constrained('platform_ad_accounts')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('external_id');
            $table->string('name');
            $table->string('objective')->nullable();
            $table->string('status', 30)->nullable();
            $table->string('buying_type', 30)->nullable();
            $table->decimal('daily_budget', 14, 2)->nullable();
            $table->decimal('lifetime_budget', 14, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id'], 'campaign_external_unique');
            $table->index(['brand_id', 'status']);
            $table->index(['platform_ad_account_id', 'status']);
        });

        Schema::create('platform_ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('platform_campaign_id')->constrained('platform_campaigns')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 30)->nullable();
            $table->string('optimization_goal')->nullable();
            $table->string('billing_event')->nullable();
            $table->decimal('daily_budget', 14, 2)->nullable();
            $table->decimal('lifetime_budget', 14, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Targeting and placement shapes differ wildly per platform.
            $table->json('targeting')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id'], 'ad_set_external_unique');
            $table->index(['brand_id', 'status']);
            $table->index(['platform_campaign_id', 'status']);
        });

        Schema::create('platform_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('platform_ad_set_id')->constrained('platform_ad_sets')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 30)->nullable();

            // Creative, flattened to what every platform has in common.
            $table->string('creative_external_id')->nullable();
            $table->text('primary_text')->nullable();
            $table->string('headline')->nullable();
            $table->text('creative_description')->nullable();
            $table->string('call_to_action', 60)->nullable();
            $table->text('destination_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('preview_url')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id'], 'ad_external_unique');
            $table->index(['brand_id', 'status']);
            $table->index(['platform_ad_set_id', 'status']);
        });

        /**
         * Daily performance. The row an insight sync upserts is identified by
         * (level, external id, date) — re-syncing a day overwrites it rather
         * than stacking duplicates.
         */
        Schema::create('platform_ad_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('platform_ad_account_id')->nullable()->constrained('platform_ad_accounts')->cascadeOnDelete();
            $table->foreignId('platform_campaign_id')->nullable()->constrained('platform_campaigns')->cascadeOnDelete();
            $table->foreignId('platform_ad_set_id')->nullable()->constrained('platform_ad_sets')->cascadeOnDelete();
            $table->foreignId('platform_ads_id')->nullable()->constrained('platform_ads')->cascadeOnDelete();

            $table->string('platform', 40);
            $table->string('level', 20);            // account, campaign, ad_set, ad
            $table->string('external_id');          // the entity the row describes
            $table->date('date');

            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('ctr', 8, 4)->nullable();
            $table->decimal('cpc', 12, 4)->nullable();
            $table->decimal('cpm', 12, 4)->nullable();
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('conversion_value', 14, 2)->default(0);

            // Per-action breakdowns; shape varies by platform and objective.
            $table->json('actions')->nullable();
            $table->json('metadata')->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamps();

            $table->unique(['platform', 'level', 'external_id', 'date'], 'insight_unique');
            // The dashboard's bread and butter: one brand over a date range.
            $table->index(['brand_id', 'level', 'date']);
            $table->index(['platform_campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ad_insights');
        Schema::dropIfExists('platform_ads');
        Schema::dropIfExists('platform_ad_sets');
        Schema::dropIfExists('platform_campaigns');
        Schema::dropIfExists('platform_ad_accounts');
    }
};
