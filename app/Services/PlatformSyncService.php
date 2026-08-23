<?php

namespace App\Services;

use App\Models\BrandIntegration;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaSyncService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one integration's sync and records what happened.
 *
 * Platform-agnostic on purpose: it opens the log, dispatches to the right
 * driver, and closes the log whatever the outcome. Adding Google Ads means
 * adding a case here and a sync service — not another scheduler, job or log.
 *
 * Nothing in here throws to the caller. One brand's expired token must never
 * stop the other brands from syncing.
 */
class PlatformSyncService
{
    /** A sync already running is left alone rather than run twice. */
    private const STALE_AFTER_MINUTES = 30;

    public function __construct(
        private readonly MetaSyncService $meta,
    ) {}

    public function syncIntegration(
        BrandIntegration $integration,
        string $type = SyncLog::TYPE_FULL,
        ?User $triggeredBy = null,
    ): SyncLog {
        if ($running = $this->runningSyncFor($integration)) {
            return $running;
        }

        $log = SyncLog::create([
            'brand_id'             => $integration->brand_id,
            'brand_integration_id' => $integration->id,
            'platform'             => $integration->platform,
            'sync_type'            => $type,
            'status'               => SyncLog::STATUS_RUNNING,
            'started_at'           => now(),
            'triggered_by'         => $triggeredBy?->id,
        ]);

        if (!$integration->isSyncable()) {
            return $this->close($log, SyncLog::STATUS_SKIPPED, $integration->tokenHasExpired()
                ? 'The Meta connection has expired. Reconnect the account to resume syncing.'
                : 'This integration is not connected.');
        }

        try {
            match ($integration->platform) {
                BrandIntegration::PLATFORM_META => $this->meta->syncIntegration($integration, $log),
                default => throw new \RuntimeException("No sync driver for platform [{$integration->platform}]."),
            };

            $integration->update(['last_synced_at' => now(), 'last_error' => null]);

            return $this->close($log, SyncLog::STATUS_SUCCESS);
        } catch (MetaApiException $e) {
            // A dead token is a state change, not just a failed run — the
            // dashboard needs to offer "Reconnect" rather than "try again".
            if ($e->needsReconnect()) {
                $integration->update([
                    'status'     => BrandIntegration::STATUS_TOKEN_EXPIRED,
                    'last_error' => $e->userMessage(),
                ]);
            } else {
                $integration->update(['last_error' => $e->userMessage()]);
            }

            Log::warning('Platform sync failed', [
                'integration' => $integration->id,
                'platform'    => $integration->platform,
                'kind'        => $e->kind,
            ]);

            return $this->close($log, SyncLog::STATUS_FAILED, $e->userMessage(), [
                'kind'      => $e->kind,
                'meta_code' => $e->metaCode,
                // Technical detail lives in the log row, not on screen.
                'technical' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error('Platform sync crashed', [
                'integration' => $integration->id,
                'error'       => $e->getMessage(),
            ]);

            $integration->update(['last_error' => 'The sync did not complete. See the sync log.']);

            return $this->close($log, SyncLog::STATUS_FAILED, 'The sync did not complete.', [
                'technical' => $e->getMessage(),
            ]);
        }
    }

    /**
     * An in-flight sync for this integration, if there is one.
     *
     * Stops a manual "Sync now" from racing the scheduler. Anything older than
     * the stale window is assumed dead — a worker that was killed mid-run must
     * not block the integration forever.
     */
    public function runningSyncFor(BrandIntegration $integration): ?SyncLog
    {
        $running = SyncLog::where('brand_integration_id', $integration->id)
            ->running()
            ->latest('started_at')
            ->first();

        if (!$running) {
            return null;
        }

        if ($running->started_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            $this->close($running, SyncLog::STATUS_FAILED, 'The sync did not finish and was abandoned.');

            return null;
        }

        return $running;
    }

    private function close(SyncLog $log, string $status, ?string $error = null, array $metadata = []): SyncLog
    {
        $log->update([
            'status'        => $status,
            'completed_at'  => now(),
            'error_message' => $error,
            'metadata'      => $metadata ?: null,
        ]);

        return $log->fresh();
    }
}
