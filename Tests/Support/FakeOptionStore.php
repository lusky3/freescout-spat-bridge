<?php

namespace Tests\Support;

use Modules\SpatBridge\Services\Support\OptionStoreInterface;

/**
 * A real, in-memory implementation of OptionStoreInterface -- not a mock
 * library -- so tests can assert on what was actually persisted rather
 * than merely that a setter was called with certain arguments. Shared by
 * any test that needs a working option store without a real FreeScout
 * \Option facade behind it.
 */
class FakeOptionStore implements OptionStoreInterface
{
    /** @var array<string, mixed> */
    private $values = [];

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->values[$key] = $value;
    }
}
