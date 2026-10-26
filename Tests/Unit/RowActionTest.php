<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\PanelRow;
use Modules\SpatBridge\Features\RowAction;
use PHPUnit\Framework\TestCase;

class RowActionTest extends TestCase
{
    public function test_getters_return_the_constructor_values(): void
    {
        $action = new RowAction('etransfers', 'match', 'Match to order…', ['log_id' => 12]);

        $this->assertSame('etransfers', $action->feature());
        $this->assertSame('match', $action->action());
        $this->assertSame('Match to order…', $action->label());
        $this->assertSame(['log_id' => 12], $action->params());
    }

    public function test_query_round_trips_the_params(): void
    {
        $params = ['log_id' => 12, 'order_id' => 5107, 'note' => 'a&b=c "d" é', 'force_mismatch' => false];
        $action = new RowAction('etransfers', 'match', 'Match', $params);

        parse_str($action->query(), $parsed);

        $this->assertSame('etransfers', $parsed['feature']);
        $this->assertSame('match', $parsed['action']);
        $this->assertSame($params, json_decode($parsed['params'], true));
    }

    public function test_query_encodes_empty_params_as_a_json_object(): void
    {
        parse_str((new RowAction('f', 'a', 'L', []))->query(), $parsed);

        $this->assertSame('{}', $parsed['params']);
    }

    public function test_panel_row_defaults_to_no_actions(): void
    {
        $this->assertSame([], (new PanelRow('Title'))->actions());
    }

    public function test_panel_row_exposes_its_actions(): void
    {
        $action = new RowAction('f', 'a', 'L', []);
        $row = new PanelRow('Title', ['x'], 'badge', 'ok', [$action]);

        $this->assertSame([$action], $row->actions());
    }
}
