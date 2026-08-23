<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic, platform-agnostic integration layer.
 *
 * One row per brand per platform. Meta is first, but nothing here is
 * Meta-specific: Google Ads and TikTok slot in by adding a `platform` value and
 * a driver, without touching the schema or the dashboard.
 *
 * Credentials live in `credentials`, encrypted at rest by the model cast, and
 * are never serialised into an API response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('platform', 40);                 // meta, google_ads, tiktok_ads
            $table->string('status', 30)->default('disconnected');

            // Encrypted blob: access token, refresh token, expiry, scopes.
            $table->text('credentials')->nullable();
            // Non-secret facts worth showing: connected account name, business id.
            $table->json('metadata')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One integration per platform per brand.
            $table->unique(['brand_id', 'platform']);
            // The scheduler sweeps by platform + status.
            $table->index(['platform', 'status']);
        });

        /**
         * Resources the user picked out of their platform account: a Facebook
         * page, an Instagram account, an ad account, a pixel. Kept generic so a
         * new platform's resource types need no new table.
         */
        Schema::create('integration_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_integration_id')->constrained('brand_integrations')->cascadeOnDelete();
            $table->string('type', 40);              // page, instagram, ad_account, pixel, business
            $table->string('external_id');           // the platform's own id — never ours
            $table->string('name')->nullable();
            $table->string('status', 30)->nullable();
            $table->json('metadata')->nullable();    // currency, timezone, platform-specific extras
            $table->boolean('is_selected')->default(true);
            $table->timestamps();

            // A resource appears once per integration, however often we sync.
            $table->unique(['brand_integration_id', 'type', 'external_id'], 'integration_resource_unique');
            $table->index(['brand_integration_id', 'type', 'is_selected'], 'integration_resource_lookup');
        });

        /**
         * One row per sync attempt. Answers "when did this last run, did it
         * work, and how much did it move" without reading application logs.
         */
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->foreignId('brand_integration_id')->nullable()->constrained('brand_integrations')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('sync_type', 40);          // full, campaigns, insights, manual
            $table->string('status', 30);             // running, success, failed, skipped
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('records_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('integration_resources');
        Schema::dropIfExists('brand_integrations');
    }
};
