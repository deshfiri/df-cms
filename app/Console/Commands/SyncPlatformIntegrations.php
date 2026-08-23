<?php

namespace App\Console\Commands;

use App\Jobs\SyncBrandIntegration;
use App\Models\BrandIntegration;
use Illuminate\Console\Command;

/**
 * Queues a sync for every connected integration on an active brand.
 *
 * Runs every 20 minutes from the scheduler. It only *queues* work — the command
 * finishes in milliseconds regardless of how many brands there are, and the
 * queue workers do the talking to Meta.
 */
class SyncPlatformIntegrations extends Command
{
    protected $signature = 'platforms:sync
        {--platform= : Limit to one platform (meta, google_ads, …)}
        {--brand= : Limit to a single brand id}';

    protected $description = 'Queue advertising data synchronisation for every connected brand integration';

    public function handle(): int
    {
        $query = BrandIntegration::query()
            ->where('status', BrandIntegration::STATUS_CONNECTED)
            // A deactivated brand keeps its data but stops costing API calls.
            ->whereHas('brand', fn ($q) => $q->where('is_active', true));

        if ($platform = $this->option('platform')) {
            $query->where('platform', $platform);
        }
        if ($brand = $this->option('brand')) {
            $query->where('brand_id', $brand);
        }

        $queued = 0;

        foreach ($query->cursor() as $integration) {
            SyncBrandIntegration::dispatch($integration->id);
            $queued++;
        }

        $this->info("Queued {$queued} integration sync(s).");

        return self::SUCCESS;
    }
}
