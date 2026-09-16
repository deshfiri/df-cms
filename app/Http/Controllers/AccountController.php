<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Storage\StorageSettings;
use App\Services\Storage\StoredFileResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly StorageSettings $storage,
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
     * Set or replace the signed-in user's profile picture.
     *
     * Goes wherever uploads currently go (Settings → Storage & CDN) and is read
     * back through the disk it was written to, like every other upload.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validateWithBag('avatar', [
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.max'   => 'A profile picture can be up to 2 MB.',
            'avatar.mimes' => 'Use a JPG, PNG or WebP image.',
        ]);

        $user = $request->user();
        $file = $request->file('avatar');
        $disk = $this->storage->activeDisk();
        $path = $file->storeAs('avatars', $user->id . '_' . now()->getTimestamp() . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg'), $disk);

        if (!$path) {
            return back()->withErrors(['avatar' => 'The picture could not be stored. Please try again.'], 'avatar');
        }

        $this->deleteAvatarFile($user);
        $user->forceFill(['avatar' => $path, 'avatar_disk' => $disk])->save();
        $this->activityLog->log('User', 'Profile Picture Updated', null, null, ['user_id' => $user->id]);

        return redirect()->route('account.edit')->with('success', 'Profile picture updated.');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->deleteAvatarFile($user);
        $user->forceFill(['avatar' => null, 'avatar_disk' => null])->save();
        $this->activityLog->log('User', 'Profile Picture Removed', null, null, ['user_id' => $user->id]);

        return redirect()->route('account.edit')->with('success', 'Profile picture removed.');
    }

    /**
     * Serve a colleague's picture.
     *
     * Any signed-in member of staff may see it — the same people who see the
     * name it sits next to, in the sidebar, chat and task lists.
     */
    public function avatar(User $user): StreamedResponse
    {
        abort_unless(filled($user->avatar), 404);

        $response = StoredFileResponse::make(
            $user->avatar_disk,
            (string) $user->avatar,
            'avatar-' . $user->id . '.' . pathinfo($user->avatar, PATHINFO_EXTENSION),
            null,
            null,
            HeaderUtils::DISPOSITION_INLINE,
        );

        // Named with an upload timestamp, so a replacement has its own URL and
        // this can be cached hard without anyone seeing a stale face.
        $response->headers->set('Cache-Control', 'private, max-age=604800');

        return $response;
    }

    private function deleteAvatarFile(User $user): void
    {
        if (blank($user->avatar)) {
            return;
        }

        try {
            Storage::disk($user->avatar_disk ?: 'local')->delete($user->avatar);
        } catch (\Throwable $e) {
            // The old picture is being replaced either way; a provider that
            // cannot delete it must not block that.
            report($e);
        }
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
