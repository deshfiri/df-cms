<?php

namespace App\Console\Commands;

use App\Services\Chat\ChatAttachmentPruner;
use App\Services\Chat\ChatRetentionSettings;
use Illuminate\Console\Command;

/**
 * Applies the chat attachment retention policy.
 *
 * Scheduled daily, and safe to run by hand. Does nothing at all while the policy
 * is switched off, which is the default — this command can be scheduled on every
 * installation without deleting anything until an administrator asks for it.
 */
class PruneChatAttachmentsCommand extends Command
{
    protected $signature = 'chat:prune-attachments
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete chat attachments older than the configured retention period';

    public function handle(ChatAttachmentPruner $pruner, ChatRetentionSettings $settings): int
    {
        if (!$settings->enabled()) {
            $this->line('Chat attachment retention is switched off — nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line(($dryRun ? '[dry run] ' : '')
            . 'Removing chat attachments sent before '
            . $settings->cutoff()->format('d M Y H:i')
            . ' (' . $settings->days() . ' days).');

        $result = $pruner->prune($dryRun);

        $this->newLine();
        $this->line('  eligible : ' . $result['eligible']);

        if ($dryRun) {
            $this->line('  would free : ' . ChatAttachmentPruner::formatBytes($result['bytes']));
            $this->newLine();
            $this->info('Nothing was deleted.');

            return self::SUCCESS;
        }

        $this->line('  deleted  : ' . $result['purged']);
        $this->line('  freed    : ' . ChatAttachmentPruner::formatBytes($result['bytes']));

        if ($result['failed'] > 0) {
            // Not a failure of the run: the rows are marked either way, so the
            // files are no longer served. Only the bytes may linger.
            $this->newLine();
            $this->warn($result['failed'] . ' file(s) could not be removed from storage and may still occupy space.');
            $this->line('They are no longer served by the application. See the log for details.');
        }

        $this->newLine();
        $this->info('Done. The messages themselves were kept.');

        return self::SUCCESS;
    }
}
