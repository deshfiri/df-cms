<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * My Account — the signed-in staff member's own settings.
 *
 * Until this existed only a Super Admin could change a staff password (from
 * Settings → Users), so nobody could change their own. Every action here acts
 * on the requester and nobody else: there is no user id in any route.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user()->load('roles:id,name');

        return view('account.edit', [
            'user'          => $user,
            'otherSessions' => $this->otherSessions($request)?->count(),
        ]);
    }

    /**
     * Change the password, proving the current one first.
     *
     * The current password guards against a session left open on a shared
     * machine; the route is throttled so that check can't be brute-forced.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string', 'current_password'],
            'password'         => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
            'logout_others'    => ['nullable', 'boolean'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
            'password.different'                => 'Choose a password different from your current one.',
        ]);

        $user = $request->user();

        // The model casts `password` as hashed. A fresh remember token means a
        // "remember me" cookie issued under the old password stops working.
        $user->forceFill([
            'password'       => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $signedOut = 0;
        if ($request->boolean('logout_others')) {
            $signedOut = (int) $this->otherSessions($request)?->delete();
        }

        // This browser keeps its sign-in, under a new session id.
        $request->session()->regenerate();

        // Never the password itself — only that it changed.
        $this->activityLog->log('User', 'Password Changed', null, null, [
            'user_id'             => $user->id,
            'other_sessions_ended' => $signedOut,
        ]);

        return redirect()->route('account.edit')->with('success', $signedOut
            ? "Password updated. You were signed out of {$signedOut} other " . Str::plural('device', $signedOut) . '.'
            : 'Password updated.');
    }

    /**
     * This user's sessions on other browsers. Only the database session driver
     * can list them; with any other driver there is nothing to show or end.
     */
    private function otherSessions(Request $request): ?Builder
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId());
    }
}
