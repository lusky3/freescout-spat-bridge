<?php

namespace Modules\SpatBridge\Services\Support;

/**
 * Time zones for dates the bridge shows. WordPress sends UTC; sidebar dates
 * are shown in the viewing agent's FreeScout time zone (the per-user
 * `timezone` core's User::dateFormat() uses), and text written once for
 * everyone (event notes) in the app time zone (`app.timezone`). Anything
 * missing or invalid falls back to the app zone, then UTC.
 */
final class Timezones
{
    /**
     * The signed-in agent's zone.
     */
    public static function agent(): \DateTimeZone
    {
        $user = null;
        if (function_exists('auth')) {
            try {
                $user = auth()->user();
            } catch (\Throwable $e) {
                $user = null;
            }
        }

        return self::forUser($user);
    }

    /**
     * @param object|null $user A FreeScout user.
     */
    public static function forUser($user): \DateTimeZone
    {
        $name = is_object($user) && isset($user->timezone) && is_string($user->timezone) ? $user->timezone : '';
        $zone = self::valid($name);

        return $zone ?? self::app();
    }

    public static function app(): \DateTimeZone
    {
        $name = '';
        if (function_exists('config')) {
            try {
                $name = (string) config('app.timezone');
            } catch (\Throwable $e) {
                $name = '';
            }
        }

        return self::named($name);
    }

    public static function named(string $name): \DateTimeZone
    {
        return self::valid($name) ?? new \DateTimeZone('UTC');
    }

    private static function valid(string $name): ?\DateTimeZone
    {
        if ($name === '') {
            return null;
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Exception $e) {
            return null;
        }
    }
}
