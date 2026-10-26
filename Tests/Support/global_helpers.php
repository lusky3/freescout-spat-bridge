<?php

/**
 * Minimal stand-ins for the global helper functions FreeScout's real,
 * fully-booted Laravel application provides via illuminate/foundation
 * (redirect()) and the translation stack (__()). This module's test suite
 * deliberately does not pull in illuminate/foundation (see the coverage
 * report for why), so SpatBridgeSettingsController::save() -- which
 * calls both at the very end of a successful request -- would otherwise
 * fatal with "Call to undefined function" the moment a save() test
 * reaches that line.
 *
 * These are guarded with function_exists() so they stay inert if a future
 * dependency change ever provides the real implementations.
 */

if (!function_exists('__')) {
    function __($key, $replace = [], $locale = null)
    {
        return $key;
    }
}

if (!function_exists('redirect')) {
    function redirect()
    {
        return new class {
            public function back()
            {
                return $this;
            }

            public function with($key, $value = null)
            {
                return $this;
            }
        };
    }
}
