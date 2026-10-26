<?php

namespace Tests\Support;

use Modules\SpatBridge\Services\Support\OptionStoreInterface;

/**
 * A real, in-memory implementation of OptionStoreInterface -- not a mock
 * library -- so tests can assert on what was actually persisted rather
 * than merely that a setter was called with certain arguments. Shared by
 * any test that needs a working option store without a real FreeScout
 * \Option facade behind it.
 *
 * It mimics \Option: arrays are stored as JSON strings (maybeSerialize) and
 * any stored string starting with "{" or "[" that decodes to an array comes
 * back as that array (maybeUnserialize).
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
        if (!array_key_exists($key, $this->values)) {
            return $default;
        }

        $value = $this->values[$key];
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->values[$key] = is_array($value) ? json_encode($value) : $value;
    }
}
