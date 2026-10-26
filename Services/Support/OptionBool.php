<?php

namespace Modules\SpatBridge\Services\Support;

/**
 * FreeScout's \Option::set() stores scalars in a text column, so a saved
 * `false` reads back as "" and `true` as "1". This turns whatever comes
 * back into a real bool.
 */
final class OptionBool
{
    /**
     * @param mixed $value
     */
    public static function from($value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool === null ? $default : $bool;
    }
}
