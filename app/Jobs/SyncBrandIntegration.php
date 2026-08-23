<?php

namespace App\Jobs;

use App\Models\BrandIntegration;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\PlatformSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Syncs one brand's platform integration.
 *
 * One job per integration, not one per run: a brand whose token has expired
 * fails its own job and leaves every other brand's sync untouched. That is the
 * whole reason this is queued rather than a loop inside a command.
 */
class SyncBrandIntegration implements ShouldQueue
{
    use Queueable;

    /** The service already records failures; retrying a dead token is pointless. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $syncType = SyncLog::TYPE_FULL,
        public readonly ?int $triggeredById = null,
    ) {}

    /**
     * A second job for the same integration waits rather than doubling the work
     * — the scheduler firing while a long manual sync is still running.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('integration:' . $this->integrationId))->dontRelease()];
    }

    public function handle(PlatformSyncService $sync): void
    {
        $integration = BrandIntegration::find($this->integrationId);

        if (!$integration) {
            return;
        }

        $sync->syncIntegration(
            $integration,
            $this->syncType,
            $this->triggeredById ? User::find($this->triggeredById) : null,
        );
    }
}
