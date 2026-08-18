<?php

namespace App\Http\Controllers;

use App\Services\Google\GoogleIntegrationSettings;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Oauth2 as GoogleOauth2;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Super Admin settings for the Google Calendar / Meet connection.
 *
 * Booking already creates the calendar event and stores the Meet link — see
 * MeetingService — but it silently no-ops until credentials exist. This is
 * where they get entered, without touching .env or the filesystem.
 */
class GoogleIntegrationController extends Controller
{
    /** Enough to create events with a Meet link, and nothing else. */
    private const SCOPES = [
        GoogleCalendar::CALENDAR_EVENTS,
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function __construct(
        private readonly GoogleIntegrationSettings $settings,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403);

            return $next($request);
        });
    }

    public function index()
    {
        return view('settings.google', [
            'settings'     => $this->settings,
            'redirectUri'  => $this->redirectUri(),
            'activeMode'   => $this->settings->activeMode(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id'         => ['nullable', 'string', 'max:255'],
            'client_secret'     => ['nullable', 'string', 'max:255'],
            'calendar_id'       => ['nullable', 'string', 'max:255'],
            'impersonate_email' => ['nullable', 'email', 'max:255'],
            // Uploaded by hand when OAuth is not an option. Not validated as
            // mimes:json — Google hands these out with assorted content types.
            'service_account'   => ['nullable', 'file', 'max:64'],
        ]);

        $this->settings->putClientCredentials($data['client_id'] ?? null, $data['client_secret'] ?? null);
        $this->settings->putCalendarId($data['calendar_id'] ?? null);
        $this->settings->putImpersonateEmail($data['impersonate_email'] ?? null);

        if ($request->hasFile('service_account')) {
            $file = $request->file('service_account');
            $json = json_decode((string) file_get_contents($file->getRealPath()), true);

            if (!is_array($json) || ($json['type'] ?? null) !== 'service_account') {
                return back()->withErrors([
                    'service_account' => 'That file is not a Google service-account key (its "type" should be "service_account").',
                ]);
            }

            // Private disk: a service-account key in public/ would be a handout.
            $path = $file->storeAs('google', 'service-account.json', 'local');
            $this->settings->putServiceAccountPath($path);
        }

        if ($request->boolean('remove_service_account')) {
            Storage::disk('local')->delete('google/service-account.json');
            $this->settings->putServiceAccountPath(null);
        }

        return back()->with('success', 'Google settings saved.');
    }

    /** Step 1: send the admin to Google to approve the app. */
    public function connect(): RedirectResponse
    {
        if (!$this->settings->hasOauthCredentials()) {
            return back()->withErrors(['client_id' => 'Save the OAuth client ID and secret first, then connect.']);
        }

        return redirect()->away($this->oauthClient()->createAuthUrl());
    }

    /** Step 2: Google sends the admin back with a one-time code. */
    public function callback(Request $request): RedirectResponse
    {
        if ($error = $request->query('error')) {
            return redirect()->route('settings.google')
                ->withErrors(['connection' => 'Google refused the connection: ' . $error]);
        }

        $code = $request->query('code');

        if (!$code) {
            return redirect()->route('settings.google')
                ->withErrors(['connection' => 'Google did not return an authorization code.']);
        }

        try {
            $client = $this->oauthClient();
            $token  = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                throw new \RuntimeException($token['error_description'] ?? $token['error']);
            }

            // The refresh token is the part worth keeping — access tokens expire
            // in an hour. Google only returns one on the first consent, which is
            // why the auth URL forces the prompt.
            $refreshToken = $token['refresh_token'] ?? null;

            if (!$refreshToken) {
                throw new \RuntimeException(
                    'Google did not return a refresh token. Remove this app at myaccount.google.com/permissions and connect again.'
                );
            }

            $client->setAccessToken($token);
            $email = null;

            try {
                $email = (new GoogleOauth2($client))->userinfo->get()->getEmail();
            } catch (Throwable) {
                // Cosmetic only — the connection works without knowing the address.
            }

            $this->settings->putConnection($refreshToken, $email);
        } catch (Throwable $e) {
            Log::warning('Google OAuth: connection failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.google')
                ->withErrors(['connection' => 'Could not complete the connection: ' . $e->getMessage()]);
        }

        return redirect()->route('settings.google')->with('success', 'Google account connected.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->settings->forgetConnection();

        return back()->with('success', 'Google account disconnected. Meeting links will stop being generated.');
    }

    /**
     * Proves the credentials actually work, rather than merely being present —
     * the failure mode this whole page exists to avoid is a booking that
     * silently produces no link.
     */
    public function test(): RedirectResponse
    {
        if (!$this->settings->isConfigured()) {
            return back()->withErrors(['connection' => 'Nothing is configured yet — connect a Google account or upload a service-account key.']);
        }

        try {
            $calendar = app(\App\Services\GoogleCalendarService::class);
            $result   = $calendar->verifyConnection();
        } catch (Throwable $e) {
            return back()->withErrors(['connection' => 'Connection test failed: ' . $e->getMessage()]);
        }

        if (!$result['ok']) {
            return back()->withErrors(['connection' => 'Connection test failed: ' . $result['message']]);
        }

        return back()->with('success', $result['message']);
    }

    /** Must match a redirect URI registered on the Google Cloud OAuth client. */
    private function redirectUri(): string
    {
        return route('settings.google.callback');
    }

    private function oauthClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setApplicationName(config('app.name'));
        $client->setClientId($this->settings->clientId());
        $client->setClientSecret($this->settings->clientSecret());
        $client->setRedirectUri($this->redirectUri());
        $client->setScopes(self::SCOPES);
        // Both are required to be handed a refresh token rather than only an
        // access token that dies in an hour.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }
}
