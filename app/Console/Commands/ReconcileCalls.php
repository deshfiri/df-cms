<?php

namespace App\Console\Commands;

use App\Services\CallService;
use Illuminate\Console\Command;

/**
 * Settles calls whose participants stopped reporting.
 *
 * The browser also runs a ring timer for immediate feedback, but a browser
 * cannot be trusted to close the loop: the tab may be shut, asleep, or offline
 * at the moment the call should expire. This sweep is the authority that stops
 * "ringing" rows living forever and blocking both users from calling anyone.
 */
class ReconcileCalls extends Command
{
    protected $signature = 'calls:reconcile';

    protected $description = 'Mark unanswered calls as missed and force-end abandoned ones';

    public function handle(CallService $calls): int
    {
        $result = $calls->reconcileStaleCalls();

        $this->info("Missed: {$result['missed']} · force-ended: {$result['stale']}");

        return self::SUCCESS;
    }
}
