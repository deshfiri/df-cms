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
            'user_id'    => Auth::id(),
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
