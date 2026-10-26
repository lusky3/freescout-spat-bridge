<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\Support\Timezones;
use PHPUnit\Framework\TestCase;

class TimezonesTest extends TestCase
{
    public function test_named_zone_falls_back_to_utc(): void
    {
        $this->assertSame('America/Toronto', Timezones::named('America/Toronto')->getName());
        $this->assertSame('UTC', Timezones::named('')->getName());
        $this->assertSame('UTC', Timezones::named('Not/AZone')->getName());
    }

    public function test_user_zone_reads_freescouts_per_user_timezone(): void
    {
        $agent = (object) ['timezone' => 'America/Vancouver'];
        $this->assertSame('America/Vancouver', Timezones::forUser($agent)->getName());
        // No user, or no usable zone on them: the app zone (UTC without Laravel).
        $this->assertSame('UTC', Timezones::forUser(null)->getName());
        $this->assertSame('UTC', Timezones::forUser((object) ['timezone' => ''])->getName());
        $this->assertSame('UTC', Timezones::forUser((object) ['timezone' => 'Mars/Base'])->getName());
    }

    public function test_without_laravel_the_agent_and_app_zones_are_utc(): void
    {
        $this->assertSame('UTC', Timezones::agent()->getName());
        $this->assertSame('UTC', Timezones::app()->getName());
    }
}
