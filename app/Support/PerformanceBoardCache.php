<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Version-keyed cache for the performance scoreboard. Every model whose data
 * feeds a score bumps the version on write (see InvalidatesPerformanceBoard),
 * which changes the cache key so the next load recomputes — immediate
 * invalidation without having to enumerate every period/department key.
 */
class PerformanceBoardCache
{
    private const VERSION_KEY = 'perf.board.version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    public static function key(string $period, ?string $department): string
    {
        return 'perf.board.v' . self::version() . ".{$period}." . ($department ?: 'all');
    }
}
