<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppMessage;
use App\Services\Storage\StorageSettings;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\WhatsAppApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls an inbound attachment down from Meta and stores it with us.
 *
 * Not an optimisation — a necessity. Meta's media URLs expire within minutes and
 * require a bearer token, so linking to one would give an agent a dead image and
 * would mean handing an access token to a browser to avoid it.
 *
 * Stored through the same storage provider as every other upload, recording the
 * disk on the row, so WhatsApp media follows the CDN setting like everything else.
 */
class DownloadWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Meta's URLs are short-lived, so retries have to be prompt. */
    public array $backoff = [5, 20];

    public function __construct(
        private readonly int $messageId,
    ) {
    }

    public function handle(MetaWhatsAppClient $client, StorageSettings $storage): void
    {
        $message = WhatsAppMessage::with('conversation.account')->find($this->messageId);

        if (!$message || !$message->media_id || $message->media_path) {
            return;   // gone, nothing to fetch, or already stored
        }

        $account = $message->conversation?->account;

        if (!$account) {
            return;
        }

        try {
            $descriptor = $client->mediaUrl($account, $message->media_id);
            $bytes      = $client->downloadMedia($account, $descriptor['url'] ?? '');
        } catch (WhatsAppApiException $e) {
            Log::warning('whatsapp.media_download_failed', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);

            // A permanent failure (expired id, revoked token) will fail the same
            // way forever; only transient ones are worth another attempt.
            if (!$e->isRetryable()) {
                return;
            }

            throw $e;
        }

        $mime      = $descriptor['mime_type'] ?? $message->media_mime ?? 'application/octet-stream';
        $extension = $this->extensionFor($mime, $message->media_name);
        $disk      = $storage->activeDisk();
        $path      = 'whatsapp/' . $message->conversation->id . '/' . Str::uuid() . ($extension ? '.' . $extension : '');

        Storage::disk($disk)->put($path, $bytes);

        $message->forceFill([
            'media_disk' => $disk,
            'media_path' => $path,
            'media_mime' => $mime,
            'media_size' => $descriptor['file_size'] ?? strlen($bytes),
            'media_name' => $message->media_name ?: ('attachment' . ($extension ? '.' . $extension : '')),
        ])->save();

        Log::info('whatsapp.media_stored', ['message_id' => $message->id, 'disk' => $disk]);
    }

    /** Prefer the original filename's extension; fall back to the mime type. */
    private function extensionFor(string $mime, ?string $originalName): ?string
    {
        if ($originalName && ($ext = pathinfo($originalName, PATHINFO_EXTENSION))) {
            return strtolower($ext);
        }

        return match (true) {
            str_contains($mime, 'jpeg')    => 'jpg',
            str_contains($mime, 'png')     => 'png',
            str_contains($mime, 'webp')    => 'webp',
            str_contains($mime, 'gif')     => 'gif',
            str_contains($mime, 'mp4')     => 'mp4',
            str_contains($mime, 'ogg')     => 'ogg',
            str_contains($mime, 'mpeg')    => 'mp3',
            str_contains($mime, 'wav')     => 'wav',
            str_contains($mime, 'amr')     => 'amr',
            str_contains($mime, 'pdf')     => 'pdf',
            default                        => null,
        };
    }
}
