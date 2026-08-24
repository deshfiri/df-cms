<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copy files that were stored on one disk across to another.
 *
 * Record-backed files never *need* this — each one remembers its own disk and
 * keeps working where it is. It exists for the two cases where that is not
 * enough: consolidating everything onto the new provider so the old one can be
 * decommissioned, and the File Manager drive, which has no per-file record and
 * so can only ever show one disk at a time.
 *
 * Copy, then update the pointer, then leave the original alone. A failed copy
 * therefore changes nothing, and the source stays as a fallback until someone
 * deliberately clears it.
 */
class StorageMigrateCommand extends Command
{
    protected $signature = 'storage:migrate
        {--from= : Disk to read from (default: local)}
        {--to= : Disk to write to (default: the active provider)}
        {--only= : Limit to "records" or "file-manager"}
        {--dry-run : Report what would move without copying anything}';

    protected $description = 'Copy stored files from one disk to another and repoint the records';

    /** table => column holding the disk name, and the column holding the path. */
    private const RECORD_SOURCES = [
        'client_documents'           => ['disk', 'path'],
        'documents'                  => ['disk', 'file_path'],
        'task_attachments'           => ['disk', 'file_path'],
        'flow_item_attachments'      => ['disk', 'file_path'],
        'messages'                   => ['attachment_disk', 'attachment_path'],
        'payment_proof_submissions'  => ['disk', 'path'],
        'client_approval_requests'   => ['disk', 'path'],
        'client_approval_responses'  => ['disk', 'path'],
        'client_action_submissions'  => ['disk', 'path'],
        'client_correction_requests' => ['disk', 'path'],
        'support_tickets'            => ['disk', 'path'],
        'support_ticket_replies'     => ['disk', 'path'],
        'client_project_updates'     => ['disk', 'path'],
    ];

    private const FILE_MANAGER_LOCAL_DISK = 'file_manager';
    private const FILE_MANAGER_REMOTE_ROOT = 'file-manager';

    public function handle(StorageSettings $settings): int
    {
        $from = (string) ($this->option('from') ?: 'local');
        $to   = (string) ($this->option('to') ?: $settings->activeDisk());
        $only = $this->option('only');
        $dry  = (bool) $this->option('dry-run');

        if ($from === $to) {
            $this->error("Source and destination are both \"{$from}\" — nothing to do.");

            return self::FAILURE;
        }

        foreach ([$from, $to] as $disk) {
            try {
                Storage::disk($disk);
            } catch (Throwable $e) {
                $this->error("Cannot open disk \"{$disk}\": " . $e->getMessage());

                return self::FAILURE;
            }
        }

        $this->line(($dry ? '[dry run] ' : '') . "Copying from <options=bold>{$from}</> to <options=bold>{$to}</>");
        $this->newLine();

        $copied = $failed = 0;

        if ($only !== 'file-manager') {
            [$c, $f] = $this->migrateRecords($from, $to, $dry);
            $copied += $c;
            $failed += $f;
        }

        if ($only !== 'records') {
            [$c, $f] = $this->migrateFileManager($from, $to, $dry);
            $copied += $c;
            $failed += $f;
        }

        $this->newLine();
        $this->info(($dry ? 'Would copy ' : 'Copied ') . $copied . ' file(s).');

        if ($failed) {
            $this->warn($failed . ' file(s) could not be copied and were left pointing at ' . $from . '.');
        }

        if (!$dry && $copied) {
            $this->newLine();
            $this->line('Originals were left in place. Remove them only once you have verified the new provider.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int} copied, failed
     */
    private function migrateRecords(string $from, string $to, bool $dry): array
    {
        $copied = $failed = 0;

        foreach (self::RECORD_SOURCES as $table => [$diskColumn, $pathColumn]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $diskColumn)) {
                continue;
            }

            $rows = DB::table($table)
                ->where($diskColumn, $from)
                ->whereNotNull($pathColumn)
                ->get(['id', $pathColumn]);

            if ($rows->isEmpty()) {
                continue;
            }

            $this->line("  {$table}: {$rows->count()} file(s)");

            foreach ($rows as $row) {
                $path = (string) $row->{$pathColumn};

                if ($dry) {
                    $copied++;
                    continue;
                }

                if ($this->copyBetween($from, $to, $path, $path)) {
                    // Repointed only after the copy landed, so a failure here
                    // leaves the record reading correctly from the old disk.
                    DB::table($table)->where('id', $row->id)->update([$diskColumn => $to]);
                    $copied++;
                } else {
                    $failed++;
                }
            }
        }

        return [$copied, $failed];
    }

    /**
     * The shared drive.
     *
     * Its paths are not stored anywhere, so the mapping is positional: the
     * local drive is a disk of its own, while on a CDN it lives under a
     * dedicated prefix of the shared bucket.
     *
     * @return array{0:int,1:int} copied, failed
     */
    private function migrateFileManager(string $from, string $to, bool $dry): array
    {
        $sourceDisk = $from === 'local' ? self::FILE_MANAGER_LOCAL_DISK : $from;
        $targetDisk = $to === 'local' ? self::FILE_MANAGER_LOCAL_DISK : $to;

        $sourceRoot = $sourceDisk === self::FILE_MANAGER_LOCAL_DISK ? '' : self::FILE_MANAGER_REMOTE_ROOT;
        $targetRoot = $targetDisk === self::FILE_MANAGER_LOCAL_DISK ? '' : self::FILE_MANAGER_REMOTE_ROOT;

        try {
            $files = Storage::disk($sourceDisk)->allFiles($sourceRoot);
        } catch (Throwable $e) {
            $this->warn('  file manager: could not be read (' . $e->getMessage() . ')');

            return [0, 0];
        }

        if (!$files) {
            return [0, 0];
        }

        $this->line('  file manager: ' . count($files) . ' file(s)');

        $copied = $failed = 0;

        foreach ($files as $file) {
            $relative = $sourceRoot !== '' && str_starts_with($file, $sourceRoot . '/')
                ? substr($file, strlen($sourceRoot) + 1)
                : $file;

            $target = trim($targetRoot . '/' . $relative, '/');

            if ($dry) {
                $copied++;
                continue;
            }

            $this->copyBetween($sourceDisk, $targetDisk, $file, $target) ? $copied++ : $failed++;
        }

        return [$copied, $failed];
    }

    /** Streamed, so a large file is never pulled into memory in one piece. */
    private function copyBetween(string $fromDisk, string $toDisk, string $fromPath, string $toPath): bool
    {
        try {
            $source = Storage::disk($fromDisk);

            if (!$source->exists($fromPath)) {
                $this->warn('    missing on ' . $fromDisk . ': ' . $fromPath);

                return false;
            }

            // Already there from an interrupted earlier run — treat as done.
            if (Storage::disk($toDisk)->exists($toPath)) {
                return true;
            }

            $stream = $source->readStream($fromPath);

            if (!is_resource($stream)) {
                $this->warn('    unreadable: ' . $fromPath);

                return false;
            }

            Storage::disk($toDisk)->put($toPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return true;
        } catch (Throwable $e) {
            $this->warn('    failed: ' . $fromPath . ' — ' . $e->getMessage());

            return false;
        }
    }
}
