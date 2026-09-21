<?php

namespace App\Services\Storage;

use App\Jobs\PushUploadToProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Stores an upload so the request can answer straight away.
 *
 * Writing to a CDN happens inside the request, so every file waits on
 * Cloudinary or R2 before the browser hears back — ten files are ten round
 * trips to someone else's server while the person watches a spinner. Instead,
 * with a provider active and a real queue, the file lands on this server's disk
 * first (fast, and readable at once: its record says 'local') and a queued
 * PushUploadToProvider copies it across and repoints the record.
 *
 * Both storage rules still hold. The record always names the disk its file is
 * really on, and the file ends up on activeDisk(). If no worker ever runs, the
 * file simply stays local and keeps working — the self-hosted default.
 *
 * With the sync queue (tests, or an install without a queue) a second hop gains
 * nothing, so the file goes straight to the provider as it always did.
 *
 * For records with `disk` and `file_path` columns (task and workflow attachments).
 */
class UploadStaging
{
    public function __construct(private readonly StorageSettings $storage) {}

    /**
     * @return array{0:string|false,1:string} [path, disk] — the path is false when the disk refused the write
     */
    public function store(UploadedFile $file, string $folder, string $name): array
    {
        $disk = $this->defers() ? 'local' : $this->storage->activeDisk();

        return [$file->storeAs($folder, $name, $disk), $disk];
    }

    /**
     * Queue the move to the provider for a record just saved from store().
     *
     * Dispatched after commit, so an upload whose transaction rolls back has
     * nothing queued for it.
     */
    public function pushLater(Model $record): void
    {
        if ($record->getAttribute('disk') === 'local' && $this->defers()) {
            PushUploadToProvider::dispatch($record, $this->storage->activeDisk())->afterCommit();
        }
    }

    private function defers(): bool
    {
        return $this->storage->activeDisk() !== 'local' && config('queue.default') !== 'sync';
    }
}
