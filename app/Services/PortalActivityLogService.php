<?php

namespace App\Services;

use App\Models\ClientPortalUser;
use App\Models\PortalActivityLog;
use Illuminate\Http\Request;

class PortalActivityLogService
{
    public function log(
        ClientPortalUser $portalUser,
        string $module,
        string $action,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?Request $request = null,
    ): void {
        $request ??= request();

        PortalActivityLog::create([
            'client_portal_user_id' => $portalUser->id,
            'client_id'             => $portalUser->client_id,
            'module'                => $module,
            'action'                => $action,
            'related_type'          => $relatedType,
            'related_id'            => $relatedId,
            'ip_address'            => $request?->ip(),
            'user_agent'            => substr((string) $request?->userAgent(), 0, 500),
        ]);
    }
}
