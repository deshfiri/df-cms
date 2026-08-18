<?php

namespace App\Services\Google;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Where the Google Calendar / Meet credentials live and which of them wins.
 *
 * Two ways to connect, in priority order:
 *
 *   1. OAuth — a Super Admin clicks "Connect Google" and signs in. Works with an
 *      ordinary Google account and reliably mints Meet links.
 *   2. Service account — a key file uploaded by hand. Only produces Meet links
 *      on Google Workspace, where it can impersonate a real user through
 *      domain-wide delegation; that is why it is the fallback, not the default.
 *
 * Anything already set in config/services.php still applies when nothing has
 * been entered here, so an existing .env deployment keeps working untouched.
 *
 * Secrets are encrypted at rest: the settings table is ordinary application
 * data that ends up in every database dump.
 */
class GoogleIntegrationSettings
{
    public const MODE_OAUTH           = 'oauth';
    public const MODE_SERVICE_ACCOUNT = 'service_account';

    public const KEY_CLIENT_ID       = 'google_oauth_client_id';
    public const KEY_CLIENT_SECRET   = 'google_oauth_client_secret';
    public const KEY_REFRESH_TOKEN   = 'google_oauth_refresh_token';
    public const KEY_ACCOUNT         = 'google_oauth_account';
    public const KEY_CALENDAR_ID     = 'google_calendar_id';
    public const KEY_SERVICE_ACCOUNT = 'google_service_account_path';
    public const KEY_IMPERSONATE     = 'google_impersonate_email';

    public function clientId(): ?string
    {
        return Setting::get(self::KEY_CLIENT_ID) ?: null;
    }

    public function clientSecret(): ?string
    {
        return $this->decrypt(Setting::get(self::KEY_CLIENT_SECRET));
    }

    public function refreshToken(): ?string
    {
        return $this->decrypt(Setting::get(self::KEY_REFRESH_TOKEN));
    }

    /** The Google account the app is acting as, for display only. */
    public function connectedAccount(): ?string
    {
        return Setting::get(self::KEY_ACCOUNT) ?: null;
    }

    public function calendarId(): string
    {
        return Setting::get(self::KEY_CALENDAR_ID)
            ?: config('services.google_calendar.calendar_id', 'primary');
    }

    public function impersonateEmail(): ?string
    {
        return Setting::get(self::KEY_IMPERSONATE)
            ?: config('services.google_calendar.impersonate_email') ?: null;
    }

    /** Absolute path to the service-account key file, if one is usable. */
    public function serviceAccountPath(): ?string
    {
        $stored = Setting::get(self::KEY_SERVICE_ACCOUNT);

        // Ask the disk for the path rather than assembling storage_path()
        // by hand — the local disk's root is configuration, not a constant.
        if ($stored && Storage::disk('local')->exists($stored)) {
            return Storage::disk('local')->path($stored);
        }

        $configured = config('services.google_calendar.credentials_path');

        if (!$configured) {
            return null;
        }

        // A relative path in .env resolves against the *current working
        // directory*, which is public/ during a web request and the project
        // root under artisan. Left alone, the same setting is found on the
        // command line and missing on every booking made through a browser —
        // which silently disables the integration instead of failing loudly.
        $path = str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);

        return is_file($path) ? $path : null;
    }

    /** OAuth needs all three parts before it can mint an access token. */
    public function hasOauthCredentials(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function isOauthConnected(): bool
    {
        return $this->hasOauthCredentials() && filled($this->refreshToken());
    }

    /** Which mechanism a call would actually use right now, if any. */
    public function activeMode(): ?string
    {
        if ($this->isOauthConnected()) {
            return self::MODE_OAUTH;
        }

        return $this->serviceAccountPath() ? self::MODE_SERVICE_ACCOUNT : null;
    }

    public function isConfigured(): bool
    {
        return $this->activeMode() !== null;
    }

    // ── Writes ───────────────────────────────────────────────────────────

    public function putClientCredentials(?string $clientId, ?string $clientSecret): void
    {
        Setting::set(self::KEY_CLIENT_ID, $clientId ?: null);

        // A blank secret means "leave the stored one alone" — the form never
        // renders the existing value back to the browser.
        if (filled($clientSecret)) {
            Setting::set(self::KEY_CLIENT_SECRET, Crypt::encryptString($clientSecret));
        }
    }

    public function putConnection(string $refreshToken, ?string $account): void
    {
        Setting::set(self::KEY_REFRESH_TOKEN, Crypt::encryptString($refreshToken));
        Setting::set(self::KEY_ACCOUNT, $account);
    }

    public function forgetConnection(): void
    {
        Setting::set(self::KEY_REFRESH_TOKEN, null);
        Setting::set(self::KEY_ACCOUNT, null);
    }

    public function putCalendarId(?string $calendarId): void
    {
        Setting::set(self::KEY_CALENDAR_ID, $calendarId ?: null);
    }

    public function putImpersonateEmail(?string $email): void
    {
        Setting::set(self::KEY_IMPERSONATE, $email ?: null);
    }

    /** @param string|null $relativePath Path on the private 'local' disk. */
    public function putServiceAccountPath(?string $relativePath): void
    {
        Setting::set(self::KEY_SERVICE_ACCOUNT, $relativePath);
    }

    /**
     * A secret written before encryption was in place, or with a different
     * APP_KEY, must not take the whole settings page down with it.
     */
    private function decrypt(?string $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
