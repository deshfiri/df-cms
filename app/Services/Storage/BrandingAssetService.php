<?php

namespace App\Services\Storage;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The logo and the favicon.
 *
 * These are the one kind of upload that is genuinely *public*: a browser fetches
 * them before anyone has logged in, on the login page and in the favicon slot.
 * That makes them the opposite of every other file in this application, which is
 * private and proxied — and it is why they need their own rules rather than
 * going through the same path as a client document.
 *
 * A CDN can only host them if it can hand a browser a URL that actually works:
 *
 *  - Cloudinary always can; its delivery URLs are public by design.
 *  - Cloudflare R2 can only if a public delivery domain has been configured. A
 *    private bucket's S3 endpoint needs a signed request, so putting a logo
 *    there would render a broken image on the login page.
 *
 * When the active provider cannot serve a public URL, branding stays on this
 * server exactly as it always has. That is a deliberate fallback, not an
 * oversight: a working logo beats a CDN-hosted one that nobody can load.
 */
class BrandingAssetService
{
    /**
     * Marks a value stored the legacy way — a path under public/, served by the
     * web server directly. Kept as its own token so existing installations keep
     * working without a migration.
     */
    public const LOCAL = 'public';

    public function __construct(
        private readonly StorageSettings $storage,
    ) {
    }

    /** Where a branding image is stored, if at all. */
    public function diskFor(string $settingKey): ?string
    {
        return Setting::get($settingKey . '_disk') ?: null;
    }

    /**
     * A URL a browser can fetch, or null when nothing is set.
     *
     * Legacy values (and anything stored while self-hosted) are relative to
     * public/ and go through asset(); CDN values are already absolute.
     */
    public function url(string $settingKey): ?string
    {
        $path = Setting::get($settingKey);

        if (!filled($path)) {
            return null;
        }

        $disk = $this->diskFor($settingKey);

        if (!$disk || $disk === self::LOCAL) {
            return asset($path);
        }

        try {
            return Storage::disk($disk)->url($path);
        } catch (Throwable) {
            // The provider stopped being able to produce URLs (credentials
            // cleared, delivery domain removed). Better a missing logo than a
            // 500 on every page in the application.
            return null;
        }
    }

    /**
     * Store a new branding image, replacing whatever was there.
     *
     * @param  string  $settingKey  e.g. 'app_logo'
     * @param  string  $folder      'uploads/logo' — used under public/ and on the CDN alike
     * @return string  The disk it landed on, for reporting back to the admin.
     */
    public function store(UploadedFile $file, string $settingKey, string $folder): string
    {
        $this->delete($settingKey);

        // Timestamped so a replacement gets a new URL and browsers do not keep
        // showing the previous one from cache. Carbon rather than time(), so the
        // clock can be moved in a test.
        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filename  = basename($folder) . '_' . now()->getTimestamp() . '.' . $extension;

        if ($this->storage->servesPublicUrls()) {
            $disk = $this->storage->activeDisk();
            $path = $folder . '/' . $filename;

            // 'public' visibility matters on R2; Cloudinary ignores it.
            Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()), 'public');

            Setting::set($settingKey, $path);
            Setting::set($settingKey . '_disk', $disk);

            return $disk;
        }

        $directory = public_path($folder);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file->move($directory, $filename);

        Setting::set($settingKey, $folder . '/' . $filename);
        Setting::set($settingKey . '_disk', self::LOCAL);

        return self::LOCAL;
    }

    /** Remove the current image from wherever it lives. */
    public function delete(string $settingKey): void
    {
        $path = Setting::get($settingKey);

        if (!filled($path)) {
            return;
        }

        $disk = $this->diskFor($settingKey);

        if (!$disk || $disk === self::LOCAL) {
            if (is_file(public_path($path))) {
                @unlink(public_path($path));
            }
        } else {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // The remote copy is already gone or unreachable; the setting
                // is being cleared either way.
            }
        }

        Setting::set($settingKey, null);
        Setting::set($settingKey . '_disk', null);
    }

    /**
     * Why branding is not on the CDN, when a CDN is otherwise active.
     *
     * Shown on the settings page so the situation is explained rather than
     * discovered.
     */
    public function fallbackReason(): ?string
    {
        if (!$this->storage->usingCdn() || $this->storage->servesPublicUrls()) {
            return null;
        }

        return 'Cloudflare R2 has no public delivery URL configured, so the logo and favicon '
            . 'stay on this server — a private bucket cannot serve them to a browser. '
            . 'Add a delivery URL under Settings → Storage & CDN to move them.';
    }
}
