<?php

namespace App\Http\Controllers\Portal\Auth;

use App\Http\Controllers\Controller;
use App\Models\ClientPortalUser;
use App\Services\PortalActivityLogService;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PortalLoginController extends Controller
{
    use AuthenticatesUsers;

    protected $redirectTo = '/portal/dashboard';

    public function __construct()
    {
        $this->middleware('guest:client_portal')->except('logout');
        $this->middleware('auth:client_portal')->only('logout');
    }

    public function showLoginForm()
    {
        return view('portal.auth.login');
    }

    public function username()
    {
        return 'login';
    }

    protected function validateLogin(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
    }

    protected function credentials(Request $request)
    {
        $login = $request->input('login');

        return [
            'password' => $request->input('password'),
            fn ($query) => $query->where('email', $login)->orWhere('phone', $login),
        ];
    }

    protected function guard()
    {
        return Auth::guard('client_portal');
    }

    protected function attemptLogin(Request $request)
    {
        if (!$this->guard()->attempt($this->credentials($request), $request->boolean('remember'))) {
            return false;
        }

        $portalUser = $this->guard()->user();

        if (!$portalUser->isActive()) {
            $this->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                $this->username() => [match ($portalUser->status) {
                    ClientPortalUser::STATUS_SUSPENDED => 'Your account has been suspended. Please contact your account manager.',
                    ClientPortalUser::STATUS_DISABLED  => 'Your account has been disabled. Please contact your account manager.',
                    ClientPortalUser::STATUS_COMPLETED => 'Your engagement has been marked complete. Please contact your account manager for access.',
                    default => 'Your account is no longer active.',
                }],
            ]);
        }

        return true;
    }

    protected function authenticated(Request $request, $portalUser)
    {
        $portalUser->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        app(PortalActivityLogService::class)->log($portalUser, 'Auth', 'Login', request: $request);
    }

    protected function loggedOut(Request $request)
    {
        return redirect()->route('portal.login');
    }
}
