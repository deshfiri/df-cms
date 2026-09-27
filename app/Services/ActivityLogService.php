<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    /**
     * $actorId names who's actually responsible for the thing being logged,
     * for the rare caller that isn't logging its own request — e.g. a
     * manager approving a pending change made by someone else, where
     * `Auth::guard('web')->id()` would otherwise silently credit the
     * approver instead of whoever really made the edit. Every other caller
     * leaves it null and keeps today's behavior.
     */
    public function log(
        string $module,
        string $action,
        ?int $clientId = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?Request $request = null,
        ?int $actorId = null,
    ): void {
        $request ??= request();

        ActivityLog::create([
            // Always the 'web' guard specifically: this column is a staff FK,
            // and Auth::id() would pick up whichever guard most recently
            // authenticated on this request — including 'client_portal',
            // whose id space never lines up with this column's users FK. See
            // the identical fix and reasoning in DocumentService.
            'user_id'    => $actorId ?? Auth::guard('web')->id(),
            'client_id'  => $clientId,
            'module'     => $module,
            'action'     => $action,
            'old_value'  => is_array($oldValue) ? json_encode($oldValue) : $oldValue,
            'new_value'  => is_array($newValue) ? json_encode($newValue) : $newValue,
            'ip_address' => $request->ip(),
            'browser'    => substr($request->userAgent() ?? '', 0, 255),
        ]);
    }
}
