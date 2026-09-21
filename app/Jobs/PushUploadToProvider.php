<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies an upload that was parked on this server across to the storage
 * provider, then points its record at the new copy. See UploadStaging.
 *
 * Copy, repoint, then clean up — in that order, so at every moment the record
 * names a disk that really holds the file:
 *   - a failed copy changes nothing; the job retries and the file keeps
 *     working from the local disk in the meantime (and for good, if the
 *     provider never comes back);
 *   - the repoint only happens if the record still points at the local copy,
 *     so an attachment removed while it was being copied does not come back —
 *     the copy just made is deleted instead.
 */
class PushUploadToProvider implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 600;

    /** Removed before the job ran: nothing left to move. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Model $record,
        public string $disk,
    ) {}

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $path = (string) $this->record->getAttribute('file_path');

        // Already moved (a retry after the repoint), or not a file at all.
        if ($this->record->getAttribute('disk') !== 'local' || $path === '' || $this->disk === 'local') {
            return;
        }

        $local = Storage::disk('local');

        // Deleted along with its task in the meantime.
        if (!$local->exists($path)) {
            return;
        }

        // Streamed, so a large file is never held in memory in one piece.
        $stream = $local->readStream($path);
        try {
            $written = Storage::disk($this->disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (!$written) {
            throw new RuntimeException("The {$this->disk} disk refused {$path}; it stays on the local disk until a retry succeeds.");
        }

        $repointed = $this->record->newQuery()
            ->whereKey($this->record->getKey())
            ->where('disk', 'local')
            ->where('file_path', $path)
            ->update(['disk' => $this->disk]);

        if ($repointed) {
            $local->delete($path);
        } else {
            Storage::disk($this->disk)->delete($path);
        }
    }
}
