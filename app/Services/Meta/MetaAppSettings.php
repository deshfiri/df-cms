<?php

namespace App\Services\Meta;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The Meta *app* credentials — one set for the whole installation.
 *
 * Distinct from BrandIntegration, which holds each brand's own access token.
 * These identify our application to Meta; those identify a customer's account
 * to us.
 *
 * Stored in settings so a Super Admin can enter them in the UI, with anything
 * already in .env used as the fallback. Same arrangement as the Google
 * integration, so there is one way to configure a platform rather than two.
 */
class MetaAppSettings
{
    public const KEY_APP_ID      = 'meta_app_id';
    public const KEY_APP_SECRET  = 'meta_app_secret';
    public const KEY_API_VERSION = 'meta_api_version';
    public const KEY_SCOPES      = 'meta_scopes';

    public function appId(): ?string
    {
        return Setting::get(self::KEY_APP_ID) ?: (config('services.meta.app_id') ?: null);
    }

    public function appSecret(): ?string
    {
        return $this->decrypt(Setting::get(self::KEY_APP_SECRET))
            ?: (config('services.meta.app_secret') ?: null);
    }

    public function apiVersion(): string
    {
        return Setting::get(self::KEY_API_VERSION)
            ?: config('services.meta.api_version', 'v21.0');
    }

    /** @return array<int,string> */
    public function scopes(): array
    {
        $stored = Setting::get(self::KEY_SCOPES);

        if (filled($stored)) {
            return array_values(array_filter(array_map('trim', explode(',', $stored))));
        }

        return config('services.meta.scopes', []);
    }

    /**
     * Where Meta sends the user back.
     *
     * Derived from the app URL rather than stored, so it cannot drift out of
     * step with the route — but it must be registered on the Meta app exactly
     * as shown, which is why the settings page displays it for copying.
     */
    public function redirectUri(): string
    {
        return config('services.meta.redirect_uri') ?: route('marketing.meta.callback');
    }

    public function isConfigured(): bool
    {
        return filled($this->appId()) && filled($this->appSecret());
    }

    // ── Writes ───────────────────────────────────────────────────────────

    public function putCredentials(?string $appId, ?string $appSecret): void
    {
        Setting::set(self::KEY_APP_ID, $appId ?: null);

        // Blank means "leave the stored secret alone" — the form never renders
        // the existing value back to the browser.
        if (filled($appSecret)) {
            Setting::set(self::KEY_APP_SECRET, Crypt::encryptString($appSecret));
        }
    }

    public function putApiVersion(?string $version): void
    {
        Setting::set(self::KEY_API_VERSION, $version ?: null);
    }

    public function putScopes(?string $scopes): void
    {
        Setting::set(self::KEY_SCOPES, $scopes ?: null);
    }

    public function forget(): void
    {
        Setting::set(self::KEY_APP_SECRET, null);
        Setting::set(self::KEY_APP_ID, null);
    }

    /** A value written before encryption, or under a different APP_KEY, must not fatal. */
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
