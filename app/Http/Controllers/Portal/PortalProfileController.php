<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PortalProfileController extends Controller
{
    use InteractsWithPortalUser;

    public function edit()
    {
        $portalUser = $this->portalUser();

        return view('portal.profile.edit', compact('portalUser'));
    }

    public function updatePassword(Request $request)
    {
        $portalUser = $this->portalUser();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'          => ['required', 'confirmed', Password::defaults()],
        ]);

        if (!Hash::check($data['current_password'], $portalUser->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $portalUser->update(['password' => $data['password']]);

        return back()->with('success', 'Password updated successfully.');
    }
}
