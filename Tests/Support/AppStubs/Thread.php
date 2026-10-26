<?php

namespace App;

/**
 * Test-only stand-in for FreeScout's App\Thread model: the note type
 * constant and a createExtended() that records its arguments instead of
 * touching a database. Like the Controller stub, it only exists through
 * composer.json's autoload-dev App\ mapping and never ships.
 */
class Thread
{
    public const TYPE_NOTE = 3;

    /** @var array<int, array{0: array, 1: mixed, 2: mixed}> */
    public static $created = [];

    /** @var \Throwable|null Thrown by the next createExtended() call when set. */
    public static $failWith;

    /**
     * @param mixed $conversation
     * @param mixed $customer
     * @return bool
     */
    public static function createExtended(array $data, $conversation = null, $customer = null)
    {
        if (self::$failWith !== null) {
            throw self::$failWith;
        }
        self::$created[] = [$data, $conversation, $customer];

        return true;
    }

    public static function reset(): void
    {
        self::$created = [];
        self::$failWith = null;
    }
}
