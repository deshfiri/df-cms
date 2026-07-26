<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Models\ClientPortalUser;
use Illuminate\Support\Facades\Auth;

trait InteractsWithPortalUser
{
    protected function portalUser(): ClientPortalUser
    {
        return Auth::guard('client_portal')->user();
    }
}
