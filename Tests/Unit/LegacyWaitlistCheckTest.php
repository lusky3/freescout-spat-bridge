<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\LegacyWaitlistCheck;
use PHPUnit\Framework\TestCase;

class LegacyWaitlistCheckTest extends TestCase
{
    public function test_flags_the_old_module_when_it_is_active(): void
    {
        $asked = [];
        $check = new LegacyWaitlistCheck(function (string $alias) use (&$asked) {
            $asked[] = $alias;

            return true;
        });

        $this->assertTrue($check->oldModuleActive());
        $this->assertSame(['splmwaitlist'], $asked);
    }

    public function test_does_not_flag_when_the_old_module_is_inactive(): void
    {
        $this->assertFalse((new LegacyWaitlistCheck(function () {
            return false;
        }))->oldModuleActive());
    }

    public function test_duplicate_panels_need_both_the_old_module_and_the_bridge_feature(): void
    {
        $active = new LegacyWaitlistCheck(function () {
            return true;
        });
        $inactive = new LegacyWaitlistCheck(function () {
            return false;
        });

        $this->assertTrue($active->duplicatePanels(true));
        $this->assertFalse($active->duplicatePanels(false));
        $this->assertFalse($inactive->duplicatePanels(true));
        $this->assertFalse($inactive->duplicatePanels(false));
    }

    public function test_the_settings_view_carries_the_exact_warning_text(): void
    {
        $blade = (string) file_get_contents(__DIR__ . '/../../Resources/views/settings/index.blade.php');

        $this->assertStringContainsString(
            'The SportsPress Waitlist (SplmWaitlist) module is still active. '
            . 'Agents will see two waitlist panels until you disable it.',
            $blade
        );
        $this->assertStringContainsString('alert alert-warning', $blade);
    }

    public function test_a_failing_module_lookup_is_not_a_warning(): void
    {
        $this->assertFalse((new LegacyWaitlistCheck(function () {
            throw new \RuntimeException('no module repository');
        }))->oldModuleActive());
    }
}
