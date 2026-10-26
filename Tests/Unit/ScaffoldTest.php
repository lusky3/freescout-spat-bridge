<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\Support\LaravelOptionStore;
use PHPUnit\Framework\TestCase;

class ScaffoldTest extends TestCase
{
    public function test_module_json_declares_the_provider_and_alias(): void
    {
        $module = json_decode((string) file_get_contents(__DIR__ . '/../../module.json'), true);

        $this->assertSame('spatbridge', $module['alias']);
        $this->assertContains(
            'Modules\\SpatBridge\\Providers\\SpatBridgeServiceProvider',
            $module['providers']
        );
    }

    public function test_option_store_round_trips(): void
    {
        $store = new LaravelOptionStore();

        $store->set('spatbridge.x', 'y');

        $this->assertSame('y', $store->get('spatbridge.x'));
    }
}
