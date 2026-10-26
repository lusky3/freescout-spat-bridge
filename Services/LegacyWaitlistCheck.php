<?php

namespace Modules\SpatBridge\Services;

/**
 * Detects the older standalone SplmWaitlist module, which this module's
 * Waitlist feature replaces. While both are active, agents see two waitlist
 * panels, so the settings page warns about it.
 */
class LegacyWaitlistCheck
{
    public const OLD_ALIAS = 'splmwaitlist';

    /** @var callable(string): bool */
    private $moduleActive;

    public function __construct(?callable $moduleActive = null)
    {
        $this->moduleActive = $moduleActive ?? [self::class, 'freescoutModuleActive'];
    }

    public function oldModuleActive(): bool
    {
        try {
            return (bool) call_user_func($this->moduleActive, self::OLD_ALIAS);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Two waitlist panels only show when both modules render one. */
    public function duplicatePanels(bool $bridgeWaitlistEnabled): bool
    {
        return $bridgeWaitlistEnabled && $this->oldModuleActive();
    }

    // @codeCoverageIgnoreStart
    //
    // FreeScout's module repository; the unit tests pass their own check.
    public static function freescoutModuleActive(string $alias): bool
    {
        return (bool) \Module::isActive($alias);
    }
    // @codeCoverageIgnoreEnd
}
