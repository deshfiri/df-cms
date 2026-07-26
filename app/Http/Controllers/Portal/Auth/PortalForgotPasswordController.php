<?php

namespace App\Http\Controllers\Portal\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Support\Facades\Password;

class PortalForgotPasswordController extends Controller
{
    use SendsPasswordResetEmails;

    public function __construct()
    {
        $this->middleware('guest:client_portal');
    }

    public function showLinkRequestForm()
    {
        return view('portal.auth.forgot-password');
    }

    public function broker()
    {
        return Password::broker('client_portal_users');
    }
}
