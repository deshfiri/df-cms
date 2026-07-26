<?php

namespace App\Models\Concerns;

use App\Support\PerformanceBoardCache;

/**
 * Bumps the performance-scoreboard cache version whenever the model changes, so
 * the cached board is invalidated the moment score-affecting data is written.
 * Uses the boot{Trait} convention so it composes with a model's own booted().
 */
trait InvalidatesPerformanceBoard
{
    public static function bootInvalidatesPerformanceBoard(): void
    {
        $bump = fn () => PerformanceBoardCache::bump();

        static::saved($bump);
        static::deleted($bump);
    }
}
