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

    /**
     * The one non-image document type a browser may also render inline —
     * every modern browser has its own sandboxed PDF viewer, isolated from
     * this origin's page scripts, which is the only reason this is safe
     * without building a PDF sanitizer (see preview()'s docblock).
     */
    public const PREVIEWABLE_DOCUMENTS = ['application/pdf'];

    /**
     * The extensions that correspond to PREVIEWABLE_IMAGES/PREVIEWABLE_DOCUMENTS
     * — a cheap, I/O-free hint for whether a listing should even offer a View
     * button, nothing more. An upload's real content is never decided by
     * this: looksPreviewable() only gates a button render; detectMimeType()
     * + preview()'s own isPreviewableImage()/isPreviewableDocument() checks
     * are what actually decide whether a file is ever streamed inline. A
     * file renamed to a safe extension still gets refused by that real
     * check — see detectMimeType().
     */
    public const PREVIEWABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'pdf'];

    public static function download(?string $disk, string $path, string $name, ?string $mime = null, ?int $size = null): StreamedResponse
    {
        return self::make($disk, $path, $name, $mime, $size, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    public static function isPreviewableImage(?string $mime): bool
    {
        return in_array(strtolower((string) $mime), self::PREVIEWABLE_IMAGES, true);
    }

    public static function isPreviewableDocument(?string $mime): bool
    {
        return in_array(strtolower((string) $mime), self::PREVIEWABLE_DOCUMENTS, true);
    }

    /**
     * Cheap, zero-I/O hint for whether a listing (a checklist row, a panel
     * table) should render a View action at all — pure extension string
     * matching, never a storage call. Safe to run for every row of a large
     * list without a per-row network/disk round trip. Never the security
     * decision: a mislabelled file that passes this still gets refused by
     * detectMimeType() + preview()'s real check the moment anyone actually
     * clicks View, so nothing is ever streamed on the strength of this
     * check alone.
     */
    public static function looksPreviewable(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::PREVIEWABLE_EXTENSIONS, true);
    }

    /**
     * The one place a submission's real, server-verified MIME type is
     * determined — a small, bounded read of the file's actual opening
     * bytes, sniffed directly with finfo. Only ever called for one file at
     * a time, on an explicit View click — never in a listing loop, see
     * looksPreviewable() for that.
     *
     * Deliberately does NOT call the disk's own mimeType(): Flysystem's
     * local adapter (FallbackMimeTypeDetector) falls back to the file's
     * *extension* whenever finfo's read of the real content is
     * "inconclusive" (text/plain, application/octet-stream, empty) — the
     * exact disguise this check exists to catch, since a plain-text file
     * renamed to ".jpg" sniffs as text/plain and would otherwise be handed
     * back as "image/jpeg". A remote disk's stored Content-Type has the
     * same problem one step earlier: it's whatever the upload believed at
     * write time, not a reflection of the bytes on read. Reading the
     * content itself and sniffing it here sidesteps both.
     *
     * PDF gets one extra, explicit check on top of finfo's own verdict: its
     * sample must literally start with the "%PDF-" signature. finfo already
     * requires this internally to report application/pdf at all, so this
     * never changes finfo's answer — it's a second, independent read of the
     * same bytes already in hand, not a second disk round trip, kept
     * because a PDF is the one previewable type whose bytes go straight to
     * the browser's own document viewer rather than an <img> tag.
     */
    public static function detectMimeType(?string $disk, string $path): ?string
    {
        try {
            $stream = Storage::disk($disk ?: 'local')->readStream($path);
        } catch (FilesystemException $e) {
            report($e);

            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        // Every format in PREVIEWABLE_IMAGES/PREVIEWABLE_DOCUMENTS carries
        // its magic bytes in its first few dozen bytes, so a small fixed
        // sample is enough — this never reads the rest of the file, however
        // large it is.
        $sample = fread($stream, 8192);
        fclose($stream);

        if (! is_string($sample) || $sample === '') {
            return null;
        }

        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample));

        if ($mime === '') {
            return null;
        }

        if ($mime === 'application/pdf' && ! str_starts_with($sample, '%PDF-')) {
            return null;
        }

        return $mime;
    }

    /**
     * A file shown in the browser rather than saved — images for thumbnails
     * and the lightbox everywhere this is called, PDFs opened in their own
     * tab wherever the caller opts in. Refuses anything outside
     * PREVIEWABLE_IMAGES (plus PREVIEWABLE_DOCUMENTS when $allowDocuments is
     * true), and locks the response down so even a mislabelled file cannot
     * act as a page.
     *
     * $allowDocuments defaults false so every existing caller (task
     * attachments, flow item attachments) keeps refusing PDFs exactly as
     * before — only ContentItemController::previewSubmission() passes true.
     * That caller also only ever hands this a server-sniffed $mime from
     * detectMimeType(), never a stored/client-reported one, so a PDF only
     * gets this far on the strength of its actual bytes.
     *
     * A PDF is never embedded in this app's own DOM (no <iframe>/<object>) —
     * it is only ever opened as a new top-level tab, so the browser's own
     * PDF viewer renders it in a browsing context isolated from this
     * origin's page scripts and cookies-bearing DOM. That isolation is the
     * browser vendor's own security boundary, not something this response
     * builds; the CSP below is defense in depth on top of it, not a
     * replacement for it. We are not sanitizing PDF content (no stripping
     * of embedded JS/actions/forms) — that is explicitly out of scope here.
     */
    public static function preview(?string $disk, string $path, string $name, ?string $mime, ?int $size = null, bool $allowDocuments = false): StreamedResponse
    {
        $mime = strtolower((string) $mime);
        $isPdf = $allowDocuments && self::isPreviewableDocument($mime);

        abort_unless(self::isPreviewableImage($mime) || $isPdf, 415, 'This file cannot be previewed. Download it instead.');

        $response = self::make($disk, $path, $name, $mime, $size, HeaderUtils::DISPOSITION_INLINE);
        $response->headers->set('Content-Security-Policy', $isPdf
            ? "default-src 'none'; sandbox"
            : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
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
        $name = trim(str_replace(['/', '\\'], '-', $name)) ?: basename($path);
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
