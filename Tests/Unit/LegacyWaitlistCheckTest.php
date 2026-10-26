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

    public function test_a_failing_module_lookup_is_not_a_warning(): void
    {
        $this->assertFalse((new LegacyWaitlistCheck(function () {
            throw new \RuntimeException('no module repository');
        }))->oldModuleActive());
    }
}
