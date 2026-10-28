<?php

namespace App;

/**
 * Test-only stand-in for FreeScout's App\Conversation model: the constants
 * the module reads (same values as core), the meta accessors and recording
 * versions of the persistence calls. Like the other AppStubs it only exists
 * through composer.json's autoload-dev.
 */
class Conversation
{
    public const PERSON_USER = 2;
    public const TYPE_PHONE = 2;
    public const STATUS_ACTIVE = 1;
    public const STATUS_PENDING = 2;
    public const STATE_PUBLISHED = 2;
    public const SOURCE_TYPE_API = 3;

    /** @var int */
    public $id = 0;

    /** @var int|null */
    public $status;

    /** @var string|null */
    public $last_reply_at;

    /** @var int|null */
    public $last_reply_from;

    /** @var array */
    public $meta = [];

    /** @var object|null */
    public $mailbox;

    /** @var string[] Persistence calls, in order. */
    public $calls = [];

    /**
     * @return mixed
     */
    public function getMeta($key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    public function setMeta($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function updateFolder(): void
    {
        $this->calls[] = 'updateFolder';
    }

    public function save(): bool
    {
        $this->calls[] = 'save';

        return true;
    }
}
