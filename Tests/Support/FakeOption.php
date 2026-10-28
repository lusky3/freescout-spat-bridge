<?php

namespace Tests\Support;

/**
 * A bare, static, in-memory stand-in for FreeScout's real \Option facade
 * (a static proxy backed by the options DB table). LaravelOptionStore's
 * whole job is delegating to this exact static API, so the fake is
 * deliberately just as thin. Aliased to the global \Option class name in
 * Tests/bootstrap.php, since LaravelOptionStore calls \Option directly
 * with no constructor injection point.
 */
class FakeOption
{
    /** @var array<string, mixed> */
    public static $values = [];

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        self::$values[$key] = $value;
    }

    public static function reset(): void
    {
        self::$values = [];
    }
}
