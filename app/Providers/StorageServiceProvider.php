<?php

namespace App\Providers;

use App\Services\Storage\Cloudinary\CloudinaryAdapter;
use App\Services\Storage\Cloudinary\CloudinaryClient;
use App\Services\Storage\StorageSettings;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use RuntimeException;

/**
 * Registers the two CDN disks that are configured from the dashboard.
 *
 * Both drivers read their credentials inside the resolver closure rather than
 * at boot. That is the whole trick: nothing touches the settings table until a
 * disk is actually used, so a fresh install, `config:cache`, and `migrate` on
 * an empty database all stay safe, and re-entering credentials in the UI takes
 * effect on the next request without a deploy.
 */
class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageSettings::class);
    }

    public function boot(): void
    {
        // Cloudflare R2 speaks S3, so Laravel's own S3 driver does the work —
        // including streaming and temporary URLs. Only the config is ours.
        Storage::extend('cloudflare-r2', function ($app, array $config) {
            $settings = $app->make(StorageSettings::class);

            if (!$settings->isConfigured(StorageSettings::PROVIDER_CLOUDFLARE)) {
                throw new RuntimeException('Cloudflare R2 is selected but its credentials are incomplete. Check Settings → Storage & CDN.');
            }

            // Settings last, so they win: r2Config() already folds in the env
            // values as its own fallback, and $config carries the raw env
            // defaults from config/filesystems.php. Merging the other way round
            // would let a stale .env quietly override the saved bucket.
            return $app->make('filesystem')->createS3Driver(
                array_merge($config, $settings->r2Config())
            );
        });

        Storage::extend('cloudinary', function ($app, array $config) {
            $settings = $app->make(StorageSettings::class);

            if (!$settings->isConfigured(StorageSettings::PROVIDER_CLOUDINARY)) {
                throw new RuntimeException('Cloudinary is selected but its credentials are incomplete. Check Settings → Storage & CDN.');
            }

            $cloudinary = $settings->cloudinaryConfig();

            $adapter = new CloudinaryAdapter(new CloudinaryClient(
                $cloudinary['cloud_name'],
                $cloudinary['api_key'],
                $cloudinary['api_secret'],
                $cloudinary['folder'] ?? null,
            ));

            return new LaravelFilesystemAdapter(
                new Flysystem($adapter, $cloudinary),
                $adapter,
                // Settings last for the same reason as R2 above.
                array_merge($config, $cloudinary),
            );
        });
    }
}
