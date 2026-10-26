<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\ETransfers\ETransfersFeature;
use PHPUnit\Framework\TestCase;

class ETransfersFeatureTest extends TestCase
{
    private function transfer(array $overrides = []): array
    {
        return array_merge([
            'log_id' => 12, 'at' => '2026-10-21T14:02:00Z', 'amount' => '325.00',
            'from_name' => 'Pat Smith', 'from_email' => 'jordan.smith@example.com',
            'reference' => 'CA8kQ2', 'status' => 'unmatched', 'order_id' => null,
        ], $overrides);
    }

    private function onHold(array $overrides = []): array
    {
        return array_merge([
            'order_id' => 5107, 'at' => '2026-10-20T10:00:00Z', 'total' => '325.00',
            'possible_matches' => [[
                'log_id' => 31, 'at' => '2026-10-21T09:00:00Z', 'amount' => '325.00',
                'from_name' => 'Pat Smith', 'reference' => 'CA9xx',
            ]],
        ], $overrides);
    }

    private function sections(array $transfers, array $orders = []): array
    {
        return ['etransfers' => ['transfers' => $transfers, 'on_hold_orders' => $orders]];
    }

    private function present(array $transfers, array $orders = [])
    {
        return (new ETransfersFeature())->present($this->sections($transfers, $orders));
    }

    private function actionData($row): array
    {
        return array_map(function ($action) {
            return [$action->feature(), $action->action(), $action->label(), $action->params()];
        }, $row->actions());
    }

    public function test_identity(): void
    {
        $feature = new ETransfersFeature();

        $this->assertSame('etransfers', $feature->key());
        $this->assertSame('e-Transfers', $feature->label());
        $this->assertSame(['etransfers'], $feature->providerKeys());
        $this->assertSame(['match'], $feature->actionKeys());
        $this->assertSame(['search', 'order'], $feature->queryKeys());
    }

    public function test_an_unmatched_transfer_row(): void
    {
        $panel = $this->present([$this->transfer()]);
        $row = $panel->rows()[0];

        $this->assertSame('Oct 21 · $325.00', $row->title());
        $this->assertSame('Unmatched', $row->badge());
        $this->assertSame('bad', $row->tone());
        $this->assertSame(['from Pat Smith · ref CA8kQ2'], $row->lines());
        $this->assertSame(
            [['etransfers', 'match', 'Match to order', ['log_id' => 12]]],
            $this->actionData($row)
        );
    }

    public function test_a_matched_transfer_row_has_no_action(): void
    {
        $panel = $this->present([$this->transfer(['status' => 'matched', 'order_id' => 4821])]);
        $row = $panel->rows()[0];

        $this->assertSame('Matched', $row->badge());
        $this->assertSame('ok', $row->tone());
        $this->assertSame(['from Pat Smith · ref CA8kQ2', '→ order #4821'], $row->lines());
        $this->assertSame([], $row->actions());
    }

    public function test_an_on_hold_order_row_with_possible_matches(): void
    {
        $panel = $this->present([], [$this->onHold()]);
        $row = $panel->rows()[0];

        $this->assertSame('Order #5107 · on hold', $row->title());
        $this->assertSame('$325.00', $row->badge());
        $this->assertSame('warn', $row->tone());
        $this->assertSame(['Possible match: Oct 21 · $325.00 from Pat Smith (ref CA9xx)'], $row->lines());
        $this->assertSame(
            [
                ['etransfers', 'match', 'Match', ['log_id' => 31, 'order_id' => 5107]],
                ['etransfers', 'match', 'Match a transfer…', ['order_id' => 5107]],
            ],
            $this->actionData($row)
        );
    }

    public function test_an_on_hold_order_without_possible_matches_can_still_pick_a_transfer(): void
    {
        $row = $this->present([], [$this->onHold(['possible_matches' => []])])->rows()[0];

        $this->assertSame([], $row->lines());
        $this->assertSame(
            [['etransfers', 'match', 'Match a transfer…', ['order_id' => 5107]]],
            $this->actionData($row)
        );
    }

    public function test_possible_match_line_copes_with_a_missing_name_and_reference(): void
    {
        $bare = ['log_id' => 31, 'at' => '2026-10-21T09:00:00Z', 'amount' => '325'];
        $order = $this->onHold(['possible_matches' => [$bare]]);
        $row = $this->present([], [$order])->rows()[0];

        $this->assertSame(['Possible match: Oct 21 · $325.00'], $row->lines());
    }

    public function summaries(): array
    {
        $t = $this->transfer();
        $m = $this->transfer(['status' => 'matched', 'order_id' => 4821]);

        return [
            'one unmatched' => [[$t, $m], [], '1 unmatched e-Transfer', true],
            'two unmatched' => [[$t, $t], [], '2 unmatched e-Transfers', true],
            'unmatched and one order' => [[$t], [$this->onHold()], '1 unmatched e-Transfer · 1 order on hold', true],
            'unmatched and two orders' => [
                [$t],
                [$this->onHold(), $this->onHold(['order_id' => 5108])],
                '1 unmatched e-Transfer · 2 orders on hold',
                true,
            ],
            'only an order' => [[$m], [$this->onHold()], '1 order on hold', true],
            'all matched' => [[$m], [], null, false],
        ];
    }

    /**
     * @dataProvider summaries
     */
    public function test_summary_and_attention(array $transfers, array $orders, ?string $summary, bool $attention): void
    {
        $panel = $this->present($transfers, $orders);

        $this->assertSame($summary, $panel->summary());
        $this->assertSame($attention, $panel->attention());
        $this->assertSame('etransfers', $panel->key());
    }

    public function test_an_empty_or_missing_section_returns_null(): void
    {
        $feature = new ETransfersFeature();

        $this->assertNull($feature->present([]));
        $this->assertNull($feature->present($this->sections([], [])));
        $this->assertNull($feature->present(['etransfers' => 'nope']));
    }

    public function test_malformed_values_do_not_throw(): void
    {
        $panel = (new ETransfersFeature())->present(['etransfers' => [
            'transfers' => [
                'junk',
                null,
                ['log_id' => 'x', 'at' => 'not a date', 'amount' => ['a'], 'from_name' => ['b'],
                    'reference' => 5, 'status' => 7, 'order_id' => 'nine'],
            ],
            'on_hold_orders' => [
                5,
                ['order_id' => 'x', 'total' => null, 'possible_matches' => 'no'],
                ['order_id' => 9, 'possible_matches' => [
                    'x',
                    ['log_id' => 0],
                    ['log_id' => 3, 'at' => 5, 'amount' => 'abc'],
                ]],
            ],
        ]]);

        $this->assertNotNull($panel);
        foreach ($panel->rows() as $row) {
            $this->assertIsString($row->title());
        }
        $titles = array_map(function ($row) {
            return $row->title();
        }, $panel->rows());
        $this->assertContains('e-Transfer', $titles);
        $this->assertContains('Order #9 · on hold', $titles);
    }

    public function test_an_unreadable_date_leaves_the_amount_alone(): void
    {
        $row = $this->present([$this->transfer(['at' => 'soon'])])->rows()[0];

        $this->assertSame('$325.00', $row->title());
    }

    public function test_dates_are_shown_in_utc(): void
    {
        $row = $this->present([$this->transfer(['at' => '2026-10-21T23:30:00-04:00'])])->rows()[0];

        $this->assertSame('Oct 22 · $325.00', $row->title());
    }

    public function test_the_pick_view_is_used_when_only_one_id_is_known(): void
    {
        $feature = new ETransfersFeature();

        $byLog = $feature->previewOverride('match', ['log_id' => 12]);
        $this->assertSame('spatbridge::action.etransfer_pick', $byLog['view']);
        $this->assertSame('order', $byLog['data']['mode']);

        $byOrder = $feature->previewOverride('match', ['order_id' => '5107']);
        $this->assertSame('spatbridge::action.etransfer_pick', $byOrder['view']);
        $this->assertSame('transfer', $byOrder['data']['mode']);
    }

    public function test_no_pick_view_when_both_ids_or_neither_are_known(): void
    {
        $feature = new ETransfersFeature();

        $this->assertNull($feature->previewOverride('match', ['log_id' => 12, 'order_id' => 5107]));
        $this->assertNull($feature->previewOverride('match', [
            'log_id' => 12,
            'order_id' => 5107,
            'force_mismatch' => true,
        ]));
        $this->assertNull($feature->previewOverride('match', []));
        $this->assertNull($feature->previewOverride('match', ['log_id' => 'x', 'order_id' => 0]));
        $this->assertNull($feature->previewOverride('other', ['log_id' => 12]));
    }

    public function test_amount_mismatch_becomes_force_mismatch(): void
    {
        $feature = new ETransfersFeature();
        $params = ['log_id' => 12, 'order_id' => 5107];

        $this->assertSame(
            ['log_id' => 12, 'order_id' => 5107, 'force_mismatch' => true],
            $feature->mapConfirmCodes('match', ['amount_mismatch'], $params)
        );
        $this->assertSame($params, $feature->mapConfirmCodes('match', [], $params));
        $this->assertSame($params, $feature->mapConfirmCodes('match', ['something_else'], $params));
    }
}
