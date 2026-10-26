<?php

namespace Modules\SpatBridge\Services\Support;

interface OptionStoreInterface
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void;
}
