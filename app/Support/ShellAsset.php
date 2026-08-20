<?php

namespace App\Support;

/**
 * Cache-busting URLs for the extracted shell assets.
 *
 * These are hand-maintained static files under public/, not Vite entries: the
 * app is served without a node process running, and a missing build manifest
 * would take every page down. A modified-time query string gives the same
 * caching behaviour — the URL changes when the file does — with nothing to
 * build.
 *
 * The stat is memoised per request, so a page referencing several assets pays
 * for each one once.
 *
 * @see resources/views/layouts/app.blade.php
 */
class ShellAsset
{
    /** @var array<string,string> */
    private static array $memo = [];

    public static function url(string $path): string
    {
        return self::$memo[$path] ??= self::build($path);
    }

    private static function build(string $path): string
    {
        $full = public_path($path);
        $version = is_file($full) ? filemtime($full) : null;

        return asset($path) . ($version ? '?v=' . $version : '');
    }

    /** Testing seam — the memo would otherwise outlive a file change. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
