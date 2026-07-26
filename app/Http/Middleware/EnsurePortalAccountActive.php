<?php

namespace App\Http\Middleware;

use App\Models\ClientPortalUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsurePortalAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $portalUser = Auth::guard('client_portal')->user();

        if ($portalUser && $portalUser->status !== ClientPortalUser::STATUS_ACTIVE) {
            $status = $portalUser->status;

            Auth::guard('client_portal')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')->with(
                'status_message',
                match ($status) {
                    ClientPortalUser::STATUS_SUSPENDED => 'Your account has been suspended. Please contact your account manager.',
                    ClientPortalUser::STATUS_DISABLED  => 'Your account has been disabled. Please contact your account manager.',
                    ClientPortalUser::STATUS_COMPLETED => 'Your engagement has been marked complete. Please contact your account manager for access.',
                    default => 'Your account is no longer active.',
                }
            );
        }

        return $next($request);
    }
}
