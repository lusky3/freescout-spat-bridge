<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Features\PanelRow;
use PHPUnit\Framework\TestCase;

class PanelTest extends TestCase
{
    public function test_panel_row_defaults_and_getters(): void
    {
        $row = new PanelRow('Ducks', ['Div 3'], 'Captain', 'ok');

        $this->assertSame('Ducks', $row->title());
        $this->assertSame(['Div 3'], $row->lines());
        $this->assertSame('Captain', $row->badge());
        $this->assertSame('ok', $row->tone());

        $bare = new PanelRow('Waitlist');

        $this->assertSame([], $bare->lines());
        $this->assertNull($bare->badge());
        $this->assertSame('muted', $bare->tone());
    }

    public function test_panel_row_rejects_unknown_tone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PanelRow('Ducks', [], null, 'loud');
    }

    public function test_panel_getters(): void
    {
        $row = new PanelRow('Ducks');
        $panel = new Panel('player', 'Player', [$row], 'Summary', true);

        $this->assertSame('player', $panel->key());
        $this->assertSame('Player', $panel->label());
        $this->assertSame([$row], $panel->rows());
        $this->assertSame('Summary', $panel->summary());
        $this->assertTrue($panel->attention());
    }

    public function test_panel_defaults(): void
    {
        $panel = new Panel('registration', 'Registration', []);

        $this->assertNull($panel->summary());
        $this->assertFalse($panel->attention());
    }
}
