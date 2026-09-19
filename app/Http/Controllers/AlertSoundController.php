<?php

namespace App\Http\Controllers;

use App\Services\SoundSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Plays an uploaded alert sound to any signed-in user.
 *
 * Every page may need it, so it is open to all staff — it serves only the
 * files set in Settings → Sounds, by event name, never by path.
 */
class AlertSoundController extends Controller
{
    public function show(Request $request, string $event, SoundSettings $sounds): Response
    {
        abort_unless(isset(SoundSettings::EVENTS[$event]), 404);

        $file = $sounds->customFile($event);
        abort_unless($file, 404);

        $bytes = null;
        try {
            // Alerts are small (capped at upload), so reading one whole is cheap
            // and lets the response answer range requests below.
            $bytes = Storage::disk($file['disk'])->get($file['path']);
        } catch (\Throwable $e) {
            report($e);
        }
        abort_if($bytes === null, 404, 'This sound is no longer available.');

        $length  = strlen($bytes);
        $headers = [
            'Content-Type'            => $file['mime'],
            'Content-Disposition'     => 'inline',
            'Accept-Ranges'           => 'bytes',
            // The URL carries a version that changes when the file does.
            'Cache-Control'           => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'",
        ];

        // Safari asks for media in byte ranges and will not play a clip that
        // cannot answer them.
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) $request->header('Range'), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $length - (int) $m[2]);
                $end   = $length - 1;
            } else {
                $start = (int) $m[1];
                $end   = $m[2] === '' ? $length - 1 : min((int) $m[2], $length - 1);
            }

            if ($length === 0 || $start > $end || $start >= $length) {
                return response('', 416, ['Content-Range' => "bytes */{$length}"] + $headers);
            }

            return response(substr($bytes, $start, $end - $start + 1), 206, $headers + [
                'Content-Range'  => "bytes {$start}-{$end}/{$length}",
                'Content-Length' => (string) ($end - $start + 1),
            ]);
        }

        return response($bytes, 200, $headers + ['Content-Length' => (string) $length]);
    }
}
