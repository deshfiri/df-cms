<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type'];

    /**
     * Container key for the per-request copy of every setting.
     *
     * There are only a few dozen rows and the layout alone asks for three of
     * them on every page. Reading the cache per key meant one round trip per
     * key — and with the database cache store, that is a query each.
     *
     * Held as a scoped container binding rather than a static: a static would
     * outlive the request and leave long-running processes (queue workers,
     * Octane) serving settings that were changed hours ago.
     */
    private const CONTAINER_KEY = 'settings.all';

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_settings()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::flushCache();
    }

    /** @return array<string,mixed> */
    public static function all_settings(): array
    {
        $container = app();

        if (!$container->resolved(self::CONTAINER_KEY)) {
            $container->instance(self::CONTAINER_KEY, Cache::rememberForever(
                self::CONTAINER_KEY,
                fn () => static::query()->pluck('value', 'key')->all()
            ));
        }

        return $container->make(self::CONTAINER_KEY);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CONTAINER_KEY);
        app()->forgetInstance(self::CONTAINER_KEY);
    }
}
