<?php

namespace App\Http\Controllers\Portal\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class PortalResetPasswordController extends Controller
{
    use ResetsPasswords;

    protected $redirectTo = '/portal/dashboard';

    public function __construct()
    {
        $this->middleware('guest:client_portal');
    }

    public function showResetForm(Request $request)
    {
        return view('portal.auth.reset-password')->with([
            'token' => $request->route('token'),
            'email' => $request->email,
        ]);
    }

    public function broker()
    {
        return Password::broker('client_portal_users');
    }

    protected function guard()
    {
        return Auth::guard('client_portal');
    }
}
