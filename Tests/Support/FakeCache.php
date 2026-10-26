<?php

namespace Tests\Support;

/**
 * A small, real (not mock-library) stand-in for FreeScout's global \Cache
 * facade, backed by an in-memory array. remember() genuinely memoizes by
 * key -- calling it twice for the same key runs the callback only once --
 * so tests can observe real cache-hit behavior, not just that remember()
 * was invoked. Every call's key/ttl pair is also recorded, which is what
 * lets SpatBridgeServiceProviderTest assert the cache TTL is 1 (a
 * regression test: Laravel 5.5's Cache::remember() TTL argument is in
 * minutes, and a prior review found this had been set 60x too long).
 */
class FakeCache
{
    /** @var array<string, mixed> */
    public static $store = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    public static $calls = [];

    /**
     * @param mixed $ttl
     * @return mixed
     */
    public static function remember(string $key, $ttl, \Closure $callback)
    {
        self::$calls[] = [$key, $ttl];

        if (array_key_exists($key, self::$store)) {
            return self::$store[$key];
        }

        return self::$store[$key] = $callback();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return array_key_exists($key, self::$store) ? self::$store[$key] : $default;
    }

    /**
     * @param mixed $value
     * @param mixed $ttl
     */
    public static function put(string $key, $value, $ttl): void
    {
        self::$calls[] = [$key, $ttl];
        self::$store[$key] = $value;
    }

    public static function forget(string $key): bool
    {
        $existed = array_key_exists($key, self::$store);
        unset(self::$store[$key]);

        return $existed;
    }

    public static function reset(): void
    {
        self::$store = [];
        self::$calls = [];
    }
}
