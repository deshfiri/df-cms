<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    public function log(
        string $module,
        string $action,
        ?int $clientId = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?Request $request = null
    ): void {
        $request ??= request();

        ActivityLog::create([
            // Always the 'web' guard specifically: this column is a staff FK,
            // and Auth::id() would pick up whichever guard most recently
            // authenticated on this request — including 'client_portal',
            // whose id space never lines up with this column's users FK. See
            // the identical fix and reasoning in DocumentService.
            'user_id'    => Auth::guard('web')->id(),
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
