<?php

namespace App\Services\Storage;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Where uploaded files go — one choice for the whole installation.
 *
 * The rule the rest of the app relies on: {@see activeDisk()} names the disk
 * new uploads are written to, and every uploaded file records that name
 * alongside its path. Reads go back to the disk the file was written to, never
 * to the current one, so switching providers never orphans what came before.
 *
 * Self-hosted is not a fallback of last resort, it is the default: if nothing
 * is configured — or a provider is selected but its credentials are incomplete
 * — activeDisk() answers 'local' and the app behaves exactly as it always has.
 *
 * Credentials live in settings so a Super Admin can enter them in the UI, with
 * .env used as the fallback. Same arrangement as MetaAppSettings and the Google
 * integration, so there is one way to configure a platform rather than three.
 */
class StorageSettings
{
    public const PROVIDER_LOCAL      = 'local';
    public const PROVIDER_CLOUDFLARE = 'cloudflare';
    public const PROVIDER_CLOUDINARY = 'cloudinary';

    /** Provider key → the filesystem disk that provider writes to. */
    public const DISKS = [
        self::PROVIDER_LOCAL      => 'local',
        self::PROVIDER_CLOUDFLARE => 'cloudflare',
        self::PROVIDER_CLOUDINARY => 'cloudinary',
    ];

    public const KEY_PROVIDER = 'storage_provider';

    // Cloudflare R2 (S3-compatible)
    public const KEY_R2_ACCOUNT_ID = 'storage_r2_account_id';
    public const KEY_R2_ACCESS_KEY = 'storage_r2_access_key';
    public const KEY_R2_SECRET     = 'storage_r2_secret';
    public const KEY_R2_BUCKET     = 'storage_r2_bucket';
    public const KEY_R2_URL        = 'storage_r2_url';

    // Cloudinary
    public const KEY_CLOUDINARY_CLOUD  = 'storage_cloudinary_cloud_name';
    public const KEY_CLOUDINARY_KEY    = 'storage_cloudinary_api_key';
    public const KEY_CLOUDINARY_SECRET = 'storage_cloudinary_api_secret';
    public const KEY_CLOUDINARY_FOLDER = 'storage_cloudinary_folder';

    // ── The one question the rest of the app asks ────────────────────────

    /**
     * The disk new uploads should be written to.
     *
     * Never throws and never returns a disk that cannot work: a provider whose
     * credentials are half-entered is treated as not connected.
     */
    public function activeDisk(): string
    {
        $provider = $this->provider();

        if ($provider === self::PROVIDER_LOCAL || !$this->isConfigured($provider)) {
            return 'local';
        }

        return self::DISKS[$provider];
    }

    public function provider(): string
    {
        $provider = (string) Setting::get(self::KEY_PROVIDER, self::PROVIDER_LOCAL);

        return array_key_exists($provider, self::DISKS) ? $provider : self::PROVIDER_LOCAL;
    }

    /** True when this provider has everything it needs to be used. */
    public function isConfigured(?string $provider = null): bool
    {
        return match ($provider ?? $this->provider()) {
            self::PROVIDER_CLOUDFLARE => $this->hasAll($this->r2Config(), ['account_id', 'key', 'secret', 'bucket']),
            self::PROVIDER_CLOUDINARY => $this->hasAll($this->cloudinaryConfig(), ['cloud_name', 'api_key', 'api_secret']),
            // Local storage is always ready — that is the whole point of it.
            default => true,
        };
    }

    /** True when files are going anywhere other than this server's own disk. */
    public function usingCdn(): bool
    {
        return $this->activeDisk() !== 'local';
    }

    /**
     * Whether the active provider can hand a browser a URL it can actually load.
     *
     * Only matters for genuinely public assets — the logo and favicon, which are
     * fetched before anyone has logged in. Everything else in this application is
     * private and proxied, so it never needs this.
     *
     * Cloudinary's delivery URLs are public by design. R2's are not: without a
     * configured delivery domain the only address is the S3 endpoint, which
     * requires a signed request and would render as a broken image.
     */
    public function servesPublicUrls(): bool
    {
        return match ($this->provider()) {
            self::PROVIDER_CLOUDINARY => $this->isConfigured(self::PROVIDER_CLOUDINARY),
            self::PROVIDER_CLOUDFLARE => $this->isConfigured(self::PROVIDER_CLOUDFLARE)
                && filled($this->r2Config()['url'] ?? null),
            default => false,
        };
    }

    // ── Provider configuration ───────────────────────────────────────────

    /**
     * Laravel S3 driver config for R2.
     *
     * R2 is S3-compatible but not S3: it has no regions (always 'auto'), and
     * its endpoint is derived from the account id rather than entered, so an
     * admin cannot mistype it into a bucket that silently does not exist.
     *
     * @return array<string,mixed>
     */
    public function r2Config(): array
    {
        $accountId = $this->value(self::KEY_R2_ACCOUNT_ID, 'filesystems.disks.cloudflare.account_id');

        return [
            'driver'                  => 's3',
            'account_id'              => $accountId,
            'key'                     => $this->value(self::KEY_R2_ACCESS_KEY, 'filesystems.disks.cloudflare.key'),
            'secret'                  => $this->secret(self::KEY_R2_SECRET, 'filesystems.disks.cloudflare.secret'),
            'bucket'                  => $this->value(self::KEY_R2_BUCKET, 'filesystems.disks.cloudflare.bucket'),
            'region'                  => 'auto',
            'endpoint'                => $accountId ? "https://{$accountId}.r2.cloudflarestorage.com" : null,
            // Public delivery base (r2.dev or a custom domain). Optional: with
            // a private bucket there is none, and files are streamed by the app.
            'url'                     => $this->value(self::KEY_R2_URL, 'filesystems.disks.cloudflare.url'),
            'use_path_style_endpoint' => true,
            'throw'                   => true,
        ];
    }

    /** @return array<string,mixed> */
    public function cloudinaryConfig(): array
    {
        return [
            'driver'     => 'cloudinary',
            'cloud_name' => $this->value(self::KEY_CLOUDINARY_CLOUD, 'filesystems.disks.cloudinary.cloud_name'),
            'api_key'    => $this->value(self::KEY_CLOUDINARY_KEY, 'filesystems.disks.cloudinary.api_key'),
            'api_secret' => $this->secret(self::KEY_CLOUDINARY_SECRET, 'filesystems.disks.cloudinary.api_secret'),
            'folder'     => $this->value(self::KEY_CLOUDINARY_FOLDER, 'filesystems.disks.cloudinary.folder'),
            'throw'      => true,
        ];
    }

    // ── Writes ───────────────────────────────────────────────────────────

    public function putProvider(string $provider): void
    {
        Setting::set(self::KEY_PROVIDER, array_key_exists($provider, self::DISKS) ? $provider : self::PROVIDER_LOCAL);
    }

    /**
     * Store R2 credentials.
     *
     * A blank secret means "leave the stored one alone" — the form never
     * renders an existing secret back to the browser, so blank is what an
     * admin editing the bucket name will send.
     */
    public function putR2(array $data): void
    {
        Setting::set(self::KEY_R2_ACCOUNT_ID, $data['account_id'] ?? null);
        Setting::set(self::KEY_R2_ACCESS_KEY, $data['access_key'] ?? null);
        Setting::set(self::KEY_R2_BUCKET, $data['bucket'] ?? null);
        Setting::set(self::KEY_R2_URL, $this->normalizeUrl($data['url'] ?? null));

        if (filled($data['secret'] ?? null)) {
            Setting::set(self::KEY_R2_SECRET, Crypt::encryptString($data['secret']));
        }
    }

    public function putCloudinary(array $data): void
    {
        Setting::set(self::KEY_CLOUDINARY_CLOUD, $data['cloud_name'] ?? null);
        Setting::set(self::KEY_CLOUDINARY_KEY, $data['api_key'] ?? null);
        Setting::set(self::KEY_CLOUDINARY_FOLDER, trim((string) ($data['folder'] ?? ''), '/') ?: null);

        if (filled($data['api_secret'] ?? null)) {
            Setting::set(self::KEY_CLOUDINARY_SECRET, Crypt::encryptString($data['api_secret']));
        }
    }

    /** Forget one provider's credentials and fall back to self-hosted. */
    public function disconnect(string $provider): void
    {
        $keys = match ($provider) {
            self::PROVIDER_CLOUDFLARE => [self::KEY_R2_ACCOUNT_ID, self::KEY_R2_ACCESS_KEY, self::KEY_R2_SECRET, self::KEY_R2_BUCKET, self::KEY_R2_URL],
            self::PROVIDER_CLOUDINARY => [self::KEY_CLOUDINARY_CLOUD, self::KEY_CLOUDINARY_KEY, self::KEY_CLOUDINARY_SECRET, self::KEY_CLOUDINARY_FOLDER],
            default => [],
        };

        foreach ($keys as $key) {
            Setting::set($key, null);
        }

        if ($this->provider() === $provider) {
            $this->putProvider(self::PROVIDER_LOCAL);
        }
    }

    /** True when a secret is on record, without ever revealing it. */
    public function hasSecret(string $key): bool
    {
        return filled(Setting::get($key));
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function value(string $settingKey, string $configKey): ?string
    {
        $stored = Setting::get($settingKey);

        return filled($stored) ? (string) $stored : (config($configKey) ?: null);
    }

    private function secret(string $settingKey, string $configKey): ?string
    {
        return $this->decrypt(Setting::get($settingKey)) ?: (config($configKey) ?: null);
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

    private function hasAll(array $config, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!filled($config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** A delivery base with a trailing slash produces double slashes in URLs. */
    private function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }
}
