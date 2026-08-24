<?php

namespace App\Services\WhatsApp;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The Meta *app* credentials for WhatsApp — one set for the whole installation.
 *
 * Distinct from WhatsAppAccount, which holds each connected number's own access
 * token. These identify our application to Meta; those identify a brand's
 * WhatsApp Business account to us.
 *
 * Deliberately separate from MetaAppSettings (the Marketing app) even though both
 * talk to Meta: the scopes differ, and coupling them would mean rotating a
 * WhatsApp secret could break live ad syncing for every connected brand.
 *
 * Same arrangement as MetaAppSettings, GoogleIntegrationSettings and
 * StorageSettings — stored in settings so a Super Admin can enter them in the UI,
 * with .env used as the fallback.
 */
class WhatsAppSettings
{
    public const KEY_APP_ID       = 'whatsapp_app_id';
    public const KEY_APP_SECRET   = 'whatsapp_app_secret';
    public const KEY_CONFIG_ID    = 'whatsapp_config_id';
    public const KEY_VERIFY_TOKEN = 'whatsapp_webhook_verify_token';
    public const KEY_API_VERSION  = 'whatsapp_api_version';

    /** Meta's Graph API versions look like v21.0. Anything else is a typo. */
    private const VERSION_PATTERN = '/^v\d+\.\d+$/';

    public function appId(): ?string
    {
        return Setting::get(self::KEY_APP_ID) ?: (config('services.whatsapp.app_id') ?: null);
    }

    public function appSecret(): ?string
    {
        return $this->decrypt(Setting::get(self::KEY_APP_SECRET))
            ?: (config('services.whatsapp.app_secret') ?: null);
    }

    /** The Embedded Signup configuration this app should launch. */
    public function configId(): ?string
    {
        return Setting::get(self::KEY_CONFIG_ID) ?: (config('services.whatsapp.config_id') ?: null);
    }

    /**
     * The string Meta echoes back when verifying the webhook subscription.
     *
     * Encrypted because anyone holding it can complete a webhook handshake in
     * our name.
     */
    public function verifyToken(): ?string
    {
        return $this->decrypt(Setting::get(self::KEY_VERIFY_TOKEN))
            ?: (config('services.whatsapp.webhook_verify_token') ?: null);
    }

    public function apiVersion(): string
    {
        $stored = Setting::get(self::KEY_API_VERSION);

        if (filled($stored) && preg_match(self::VERSION_PATTERN, $stored)) {
            return $stored;
        }

        $configured = (string) config('services.whatsapp.api_version', 'v21.0');

        return preg_match(self::VERSION_PATTERN, $configured) ? $configured : 'v21.0';
    }

    /**
     * Where Meta sends webhook deliveries.
     *
     * Derived from the app URL rather than stored, so it cannot drift out of step
     * with the route — but it must be registered on the Meta app exactly as
     * shown, which is why the settings page displays it for copying.
     */
    public function webhookUrl(): string
    {
        return route('whatsapp.webhook');
    }

    /** Enough to run Embedded Signup and to sign API calls. */
    public function isConfigured(): bool
    {
        return filled($this->appId())
            && filled($this->appSecret())
            && filled($this->verifyToken());
    }

    /** Embedded Signup additionally needs the configuration id. */
    public function canOnboard(): bool
    {
        return $this->isConfigured() && filled($this->configId());
    }

    // ── Writes ───────────────────────────────────────────────────────────

    public function putCredentials(?string $appId, ?string $appSecret, ?string $configId): void
    {
        Setting::set(self::KEY_APP_ID, $appId ?: null);
        Setting::set(self::KEY_CONFIG_ID, $configId ?: null);

        // Blank means "leave the stored secret alone" — the form never renders
        // an existing secret back to the browser.
        if (filled($appSecret)) {
            Setting::set(self::KEY_APP_SECRET, Crypt::encryptString($appSecret));
        }
    }

    public function putVerifyToken(?string $token): void
    {
        if (filled($token)) {
            Setting::set(self::KEY_VERIFY_TOKEN, Crypt::encryptString($token));
        }
    }

    public function putApiVersion(?string $version): void
    {
        Setting::set(
            self::KEY_API_VERSION,
            filled($version) && preg_match(self::VERSION_PATTERN, $version) ? $version : null,
        );
    }

    /**
     * A verify token nobody has to invent.
     *
     * Generated rather than typed so it is long and random by default; an admin
     * who wants their own may still overwrite it.
     */
    public function generateVerifyToken(): string
    {
        $token = Str::random(48);
        $this->putVerifyToken($token);

        return $token;
    }

    public function forget(): void
    {
        foreach ([self::KEY_APP_ID, self::KEY_APP_SECRET, self::KEY_CONFIG_ID, self::KEY_VERIFY_TOKEN] as $key) {
            Setting::set($key, null);
        }
    }

    /** True when a secret is on record, without ever revealing it. */
    public function hasSecret(string $key): bool
    {
        return filled(Setting::get($key));
    }

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
