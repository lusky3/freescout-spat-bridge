<?php

namespace Tests\Support;

/**
 * Stand-in for the Tags module's Tag entity with the static API TagService
 * expects (attachByName / detachByName). Records each call.
 */
class FakeTag
{
    /** @var array<int, array{0: string, 1: string, 2: int}> */
    public static $calls = [];

    /** @var \Throwable|null */
    public static $failWith;

    public static function attachByName($name, $conversationId)
    {
        self::record('attach', $name, $conversationId);
    }

    public static function detachByName($name, $conversationId)
    {
        self::record('detach', $name, $conversationId);
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$failWith = null;
    }

    private static function record(string $call, $name, $conversationId): void
    {
        if (self::$failWith !== null) {
            throw self::$failWith;
        }
        self::$calls[] = [$call, (string) $name, (int) $conversationId];
    }
}
