<?php

namespace App\Libraries;

/**
 * Cache for rendered public pages. Every key carries a generation number, so
 * flush() drops all of them at once by bumping it (called after any admin change).
 */
class SiteCache
{
    private const GENERATION_KEY = 'site_generation';

    public static function remember(string $key, int $ttl, callable $build): mixed
    {
        $cache   = cache();
        $fullKey = 'site_' . self::generation() . '_' . md5($key);
        $value   = $cache->get($fullKey);

        if ($value === null) {
            $value = $build();
            $cache->save($fullKey, $value, $ttl);
        }

        return $value;
    }

    public static function flush(): void
    {
        cache()->save(self::GENERATION_KEY, self::generation() + 1, 0);
    }

    private static function generation(): int
    {
        return (int) (cache()->get(self::GENERATION_KEY) ?? 1);
    }
}
