<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzips HTML/JSON responses when the web server is not doing it.
 *
 * This is a fallback, not the preferred answer. It can only compress what PHP
 * renders — static files under public/ (the vendored libraries, shell.css,
 * shell-*.js) are served by the web server without ever entering Laravel, so
 * they stay uncompressed no matter what this does. Bootstrap's stylesheet alone
 * is 232 KB uncompressed.
 *
 * In production, `gzip on` in nginx covers both, does it in C rather than PHP,
 * and can serve pre-compressed files from disk. When nginx handles it, set
 * COMPRESS_RESPONSES=false and this stops doing redundant work — see
 * deploy/nginx.conf.example.
 *
 * It stays enabled by default because the app currently runs under
 * `php artisan serve`, which compresses nothing at all.
 */
class CompressResponse
{
    /** Below this, the gzip header costs more than the saving. */
    private const MIN_BYTES = 1024;

    /** Only text-shaped payloads; images and archives are already compressed. */
    private const COMPRESSIBLE = [
        'text/html', 'text/plain', 'text/css', 'text/xml',
        'application/json', 'application/javascript', 'application/xml',
        'text/javascript', 'application/ld+json',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($content, 6);

        // A failed encode must not blank the page.
        if ($compressed === false || strlen($compressed) >= strlen($content)) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        // Caches must not serve the gzipped body to a client that cannot read it.
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        // Turned off wherever the web server compresses for us.
        if (!config('app.compress_responses', true)) {
            return false;
        }

        if (!str_contains(strtolower($request->headers->get('Accept-Encoding', '')), 'gzip')) {
            return false;
        }

        // Streamed and file responses have no in-memory body to encode, and
        // reading one would defeat the point of streaming it.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        $contentType = strtolower(strtok((string) $response->headers->get('Content-Type'), ';'));

        return in_array($contentType, self::COMPRESSIBLE, true);
    }
}
