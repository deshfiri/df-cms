<?php

namespace App\Services\Chat;

use App\Models\Message;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes chat attachments once they are past the retention window.
 *
 * Two rules shape everything here.
 *
 * The message row is never touched. Retracting a message in this module has
 * always been a flag rather than a delete, because monitors are expected to see
 * what was actually said; pruning applies the same principle to the file — the
 * bytes go, the record that a file existed stays, so a thread reads as
 * "report.pdf — no longer available" rather than as a gap.
 *
 * And the file is deleted from the disk it was *written* to, read back from the
 * row, never from whichever provider happens to be active now. An attachment
 * uploaded before a CDN switch still lives where it was put.
 */
class ChatAttachmentPruner
{
    public function __construct(
        private readonly ChatRetentionSettings $settings,
        private readonly ActivityLogService $activityLog,
    ) {
    }

    /**
     * @param  bool  $dryRun  Report what would go without touching anything.
     * @return array{eligible:int, purged:int, failed:int, bytes:int}
     */
    public function prune(bool $dryRun = false): array
    {
        $result = ['eligible' => 0, 'purged' => 0, 'failed' => 0, 'bytes' => 0];

        if (!$this->settings->enabled()) {
            return $result;
        }

        $cutoff = $this->settings->cutoff();

        // Chunked by id: the set is mutated as it is walked, and an offset-based
        // pager would skip rows every time the result set shrinks beneath it.
        $this->eligible($cutoff)->chunkById(200, function ($messages) use (&$result, $dryRun) {
            foreach ($messages as $message) {
                $result['eligible']++;
                $result['bytes'] += (int) $message->attachment_size;

                if ($dryRun) {
                    continue;
                }

                $this->purge($message)
                    ? $result['purged']++
                    : $result['failed']++;
            }
        });

        if (!$dryRun && $result['purged'] > 0) {
            // An audit trail for a destructive scheduled job. Never names the
            // participants or the file contents — only the scale of it.
            $this->activityLog->log(
                module: 'Chat',
                action: 'Attachments Pruned',
                clientId: null,
                newValue: [
                    'purged'         => $result['purged'],
                    'failed'         => $result['failed'],
                    'bytes_freed'    => $result['bytes'],
                    'retention_days' => $this->settings->days(),
                ],
            );
        }

        return $result;
    }

    /** What the policy currently covers, without deleting anything. */
    public function preview(): array
    {
        return $this->prune(dryRun: true);
    }

    /**
     * Messages still holding a file, older than the cutoff.
     *
     * `attachment_purged_at` null is what makes this idempotent — a message
     * already pruned is never revisited, however often the job runs.
     */
    private function eligible(\Illuminate\Support\Carbon $cutoff)
    {
        return Message::query()
            ->whereNotNull('attachment_path')
            ->whereNull('attachment_purged_at')
            ->where('created_at', '<', $cutoff);
    }

    /**
     * Remove one attachment's bytes and mark the row.
     *
     * The row is marked whether or not the delete succeeded, and that is
     * deliberate: a file already gone from the provider, or on a disk that no
     * longer resolves, must not be retried on every run forever. The marker
     * records our intent — the file is not to be served again either way.
     */
    private function purge(Message $message): bool
    {
        $disk = $message->attachment_disk ?: 'local';
        $path = $message->attachment_path;
        $ok   = true;

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            $ok = false;

            Log::warning('chat.attachment_prune_failed', [
                'message_id' => $message->id,
                'disk'       => $disk,
                'error'      => $e->getMessage(),
            ]);
        }

        $message->forceFill([
            'attachment_path'      => null,
            'attachment_purged_at' => now(),
        ])->save();

        return $ok;
    }

    /**
     * What is currently being held, for the settings screen.
     *
     * "Keep track" in the plainest sense: how many files exist, how much space
     * they take, and how many the policy would remove on its next run.
     *
     * @return array{files:int, bytes:int, purged:int, oldest:?string}
     */
    public function inventory(): array
    {
        $live = Message::query()->whereNotNull('attachment_path');

        return [
            'files'  => (clone $live)->count(),
            'bytes'  => (int) (clone $live)->sum('attachment_size'),
            'purged' => Message::whereNotNull('attachment_purged_at')->count(),
            'oldest' => (clone $live)->min('created_at'),
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}
