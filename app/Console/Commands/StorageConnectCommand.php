<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageProbe;
use App\Services\Storage\StorageSettings;
use Illuminate\Console\Command;

/**
 * Connect a storage provider from the command line.
 *
 * The dashboard form does the same thing, but this exists because a form has
 * more ways to go wrong than a prompt: a validation error scrolled out of view,
 * a session that expired, a save that was never actually clicked. When files are
 * still landing locally after someone is sure they connected a CDN, this settles
 * it — it saves, proves the credentials with a real round trip, and activates,
 * in one pass with the outcome printed.
 *
 * Secrets are read with hidden input and never echoed back.
 */
class StorageConnectCommand extends Command
{
    protected $signature = 'storage:connect
        {provider? : cloudinary or cloudflare}
        {--no-activate : Save and test the credentials without switching uploads over}';

    protected $description = 'Save, test and activate a storage provider';

    public function handle(StorageSettings $settings, StorageProbe $probe): int
    {
        $provider = $this->argument('provider') ?: $this->choice(
            'Which provider?',
            [StorageSettings::PROVIDER_CLOUDINARY, StorageSettings::PROVIDER_CLOUDFLARE],
            0,
        );

        if (!in_array($provider, [StorageSettings::PROVIDER_CLOUDINARY, StorageSettings::PROVIDER_CLOUDFLARE], true)) {
            $this->error('Provider must be "cloudinary" or "cloudflare".');

            return self::FAILURE;
        }

        $provider === StorageSettings::PROVIDER_CLOUDINARY
            ? $this->collectCloudinary($settings)
            : $this->collectR2($settings);

        if (!$settings->isConfigured($provider)) {
            $this->error('Those credentials are incomplete — nothing was activated.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Testing the connection…');

        $result = $probe->run(StorageSettings::DISKS[$provider]);

        foreach ($result['steps'] as $step) {
            $this->line('  ' . ($step['ok'] ? '<fg=green>OK  </>' : '<fg=red>FAIL</>') . ' ' . $step['label']);
        }

        if (!$result['ok']) {
            $this->newLine();
            $this->error($result['message']);
            $this->line('Credentials were saved but NOT activated. Uploads still go to ' . $settings->activeDisk() . '.');

            return self::FAILURE;
        }

        $this->info($result['message']);

        if ($this->option('no-activate')) {
            $this->newLine();
            $this->warn('Saved and tested, but not activated (--no-activate). Uploads still go to ' . $settings->activeDisk() . '.');

            return self::SUCCESS;
        }

        $settings->putProvider($provider);

        $this->newLine();
        $this->info('Connected. New uploads now go to ' . $settings->activeDisk() . '.');

        if (!$settings->servesPublicUrls()) {
            $this->newLine();
            $this->warn('The logo and favicon will stay on this server: they are fetched by a browser '
                . 'before login, and this provider has no public delivery URL configured.');
        }

        $this->newLine();
        $this->line('Existing files stay where they are and keep working.');
        $this->line('To move them across:  <options=bold>php artisan storage:migrate</>');

        return self::SUCCESS;
    }

    private function collectCloudinary(StorageSettings $settings): void
    {
        $this->line('From the Cloudinary dashboard, under Account Details.');

        $settings->putCloudinary([
            'cloud_name' => trim((string) $this->ask('Cloud name')),
            'api_key'    => trim((string) $this->ask('API key')),
            'api_secret' => trim((string) $this->secret('API secret (hidden)')),
            'folder'     => trim((string) $this->ask('Folder (optional, keeps this app\'s files apart)', '')),
        ]);
    }

    private function collectR2(StorageSettings $settings): void
    {
        $this->line('From Cloudflare R2. The API token needs Object Read & Write on the bucket.');

        $settings->putR2([
            'account_id' => trim((string) $this->ask('Account ID')),
            'access_key' => trim((string) $this->ask('Access key ID')),
            'secret'     => trim((string) $this->secret('Secret access key (hidden)')),
            'bucket'     => trim((string) $this->ask('Bucket name')),
            'url'        => trim((string) $this->ask('Public delivery URL (optional — needed for the logo to be served from R2)', '')),
        ]);
    }
}
