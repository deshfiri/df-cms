<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends a stored upload back to the browser, from whichever disk holds it.
 *
 * Storage::download() asks the disk for the file's type and size just before
 * streaming. On a local disk that's free; on a CDN it's extra requests whose
 * answers can disagree with the bytes that follow — a missing length went out
 * as "Content-Length: 0" and the browser saved an empty file. Every upload
 * already records its own type and size, so those are what's sent.
 *
 * The stream is opened before a single header goes out, so a file that can't
 * be read is a clean 404 instead of a download that dies halfway.
 */
final class StoredFileResponse
{
    /**
     * Image types a browser may render inline from our own origin.
     *
     * Raster formats only. SVG is deliberately absent: it is a document that can
     * carry script, so an uploaded one would run with this site's session.
     */
    public const PREVIEWABLE_IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];

    public static function download(?string $disk, string $path, string $name, ?string $mime = null, ?int $size = null): StreamedResponse
    {
        return self::make($disk, $path, $name, $mime, $size, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    public static function isPreviewableImage(?string $mime): bool
    {
        return in_array(strtolower((string) $mime), self::PREVIEWABLE_IMAGES, true);
    }

    /**
     * An image shown in the page rather than saved — for thumbnails and the
     * lightbox. Refuses anything outside PREVIEWABLE_IMAGES, and locks the
     * response down so even a mislabelled file cannot act as a page.
     */
    public static function preview(?string $disk, string $path, string $name, ?string $mime, ?int $size = null): StreamedResponse
    {
        abort_unless(self::isPreviewableImage($mime), 415, 'This file cannot be previewed. Download it instead.');

        $response = self::make($disk, $path, $name, strtolower((string) $mime), $size, HeaderUtils::DISPOSITION_INLINE);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('Cache-Control', 'private, max-age=600');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }

    public static function make(?string $disk, string $path, string $name, ?string $mime, ?int $size, string $disposition): StreamedResponse
    {
        $stream = null;

        if ($path !== '') {
            try {
                // Returns null rather than throwing when the disk doesn't throw.
                $stream = Storage::disk($disk ?: 'local')->readStream($path);
            } catch (FilesystemException $e) {
                report($e);
            }
        }

        abort_unless(is_resource($stream), 404, 'This file is no longer available.');

        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        // Content-Disposition refuses slashes in a name, and needs a plain-ASCII
        // fallback for names like "রিপোর্ট.pdf".
        $name     = trim(str_replace(['/', '\\'], '-', $name)) ?: basename($path);
        $fallback = str_replace('%', '', Str::ascii($name)) ?: 'download';

        $response->headers->set('Content-Type', $mime ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition($disposition, $name, $fallback));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($size !== null && $size > 0) {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }
}
