<?php

namespace App\Console\Commands;

use App\Services\FileManagerService;
use App\Services\Storage\StorageProbe;
use App\Services\Storage\StorageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answers "why are my files still going to the local disk?" without guesswork.
 *
 * The failure this exists for is a quiet one: credentials that were entered but
 * never activated, or a provider that was activated and later became unusable
 * (a rotated APP_KEY makes the stored secret undecryptable). Both leave the app
 * writing to the local disk while the dashboard looks connected, so the report
 * below always says which disk is winning and why.
 */
class StorageStatusCommand extends Command
{
    protected $signature = 'storage:status {--test : Also run a live write/read/delete against the active provider}';

    protected $description = 'Show where uploaded files are being stored, and why';

    public function handle(StorageSettings $settings, StorageProbe $probe, FileManagerService $fileManager): int
    {
        $provider = $settings->provider();
        $active   = $settings->activeDisk();

        $this->newLine();
        $this->line('<options=bold>Storage</>');
        $this->line('  Selected provider   : ' . $provider);
        $this->line('  New uploads go to   : <options=bold>' . $active . '</>');
        $this->line('  File Manager drive  : ' . $fileManager->diskName());
        // Branding follows different rules to everything else, so it gets its
        // own line — this is exactly the thing that silently stays local.
        $this->line('  Logo / favicon      : ' . ($settings->servesPublicUrls() ? $active : 'local (public/)'));

        // The single most useful line in this report.
        if ($provider !== StorageSettings::PROVIDER_LOCAL && $active === 'local') {
            $this->newLine();
            $this->error('  ' . $provider . ' is selected but is NOT being used.');
            $this->line('  Its credentials are incomplete or could not be decrypted, so uploads');
            $this->line('  fall back to the local disk. Re-enter them in Settings → Storage & CDN.');
        }

        $this->newLine();
        $this->line('<options=bold>Providers</>');
        foreach ([StorageSettings::PROVIDER_CLOUDFLARE, StorageSettings::PROVIDER_CLOUDINARY] as $candidate) {
            $configured = $settings->isConfigured($candidate);
            $state = match (true) {
                $candidate === $provider && $configured => '<fg=green>active</>',
                $configured                            => '<fg=yellow>configured, not activated</>',
                default                                => '<fg=gray>not set up</>',
            };
            $this->line('  ' . str_pad($candidate, 12) . ' : ' . $state);
        }

        if ($provider !== StorageSettings::PROVIDER_LOCAL && $settings->isConfigured($provider) && $active !== 'local') {
            // Nothing to warn about; the selected provider really is in use.
        } elseif ($settings->isConfigured(StorageSettings::PROVIDER_CLOUDFLARE) || $settings->isConfigured(StorageSettings::PROVIDER_CLOUDINARY)) {
            $this->newLine();
            $this->warn('  Saving credentials does not move any uploads — a provider must be activated.');
        }

        if ($settings->usingCdn() && !$settings->servesPublicUrls()) {
            $this->newLine();
            $this->warn('  The logo and favicon cannot go to this provider: they are fetched by a');
            $this->line('  browser before login, and no public delivery URL is configured for it.');
        }

        $this->reportUsage();

        if ($this->option('test')) {
            $this->newLine();
            $this->line('<options=bold>Live test on "' . $active . '"</>');

            $result = $probe->run($active);
            foreach ($result['steps'] as $step) {
                $this->line('  ' . ($step['ok'] ? '<fg=green>OK  </>' : '<fg=red>FAIL</>') . ' ' . $step['label']);
            }

            $result['ok'] ? $this->info('  ' . $result['message']) : $this->error('  ' . $result['message']);

            if (!$result['ok']) {
                return self::FAILURE;
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /** Where the files that already exist are actually living. */
    private function reportUsage(): void
    {
        $sources = [
            ['client_documents', 'disk'],
            ['documents', 'disk'],
            ['task_attachments', 'disk'],
            ['flow_item_attachments', 'disk'],
            ['messages', 'attachment_disk'],
            ['payment_proof_submissions', 'disk'],
            ['client_approval_requests', 'disk'],
            ['client_approval_responses', 'disk'],
            ['client_action_submissions', 'disk'],
            ['client_correction_requests', 'disk'],
            ['support_tickets', 'disk'],
            ['support_ticket_replies', 'disk'],
            ['client_project_updates', 'disk'],
        ];

        $totals = [];

        foreach ($sources as [$table, $column]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull($column)
                ->selectRaw("{$column} as disk, COUNT(*) as total")
                ->groupBy($column)
                ->pluck('total', 'disk');

            foreach ($rows as $disk => $total) {
                $totals[$disk] = ($totals[$disk] ?? 0) + (int) $total;
            }
        }

        $this->newLine();
        $this->line('<options=bold>Stored files by disk</>');

        if (!$totals) {
            $this->line('  (none yet)');

            return;
        }

        arsort($totals);
        foreach ($totals as $disk => $count) {
            $this->line('  ' . str_pad((string) $disk, 12) . ' : ' . number_format($count));
        }
    }
}
