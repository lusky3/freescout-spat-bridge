<?php

namespace Modules\SpatBridge\Features\ETransfers;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Features\PanelRow;
use Modules\SpatBridge\Features\RowAction;

/**
 * e-Transfers sent from the customer's email, and their on-hold orders, with
 * "Match" actions that go through the preview and confirm pop-up.
 *
 * Wire data comes from another system, so every key may be missing and every
 * value may have the wrong type; nothing here throws on that.
 */
final class ETransfersFeature implements Feature
{
    private const SEP = ' · ';
    private const PICK_VIEW = 'spatbridge::action.etransfer_pick';

    public function key(): string
    {
        return 'etransfers';
    }

    public function label(): string
    {
        return __('e-Transfers');
    }

    public function providerKeys(): array
    {
        return ['etransfers'];
    }

    public function actionKeys(): array
    {
        return ['match'];
    }

    public function queryKeys(): array
    {
        return ['search', 'order'];
    }

    public function present(array $sections): ?Panel
    {
        $section = $this->arr($sections, 'etransfers');

        $rows = [];
        $unmatched = 0;
        foreach ($this->arr($section, 'transfers') as $transfer) {
            if (!is_array($transfer)) {
                continue;
            }
            $row = $this->transferRow($transfer);
            if ($row->tone() === 'bad') {
                $unmatched++;
            }
            $rows[] = $row;
        }

        $onHold = 0;
        foreach ($this->arr($section, 'on_hold_orders') as $order) {
            $row = is_array($order) ? $this->orderRow($order) : null;
            if ($row !== null) {
                $onHold++;
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return null;
        }

        return new Panel(
            $this->key(),
            $this->label(),
            $rows,
            $this->summary($unmatched, $onHold),
            $unmatched + $onHold > 0
        );
    }

    public function previewOverride(string $action, array $params): ?array
    {
        if ($action !== 'match') {
            return null;
        }

        $hasLog = $this->id($params, 'log_id') > 0;
        $hasOrder = $this->id($params, 'order_id') > 0;
        if ($hasLog === $hasOrder) {
            return null;
        }

        // Mode names what the agent still has to pick: an order when only a
        // transfer is known, and the other way round.
        return ['view' => self::PICK_VIEW, 'data' => ['mode' => $hasLog ? 'order' : 'transfer']];
    }

    public function mapConfirmCodes(string $action, array $codes, array $params): array
    {
        if (in_array('amount_mismatch', $codes, true)) {
            $params['force_mismatch'] = true;
        }

        return $params;
    }

    private function summary(int $unmatched, int $onHold): ?string
    {
        $parts = [];
        if ($unmatched > 0) {
            $parts[] = $unmatched === 1
                ? __('1 unmatched e-Transfer')
                : $unmatched . ' ' . __('unmatched e-Transfers');
        }
        if ($onHold > 0) {
            $parts[] = $onHold === 1 ? __('1 order on hold') : $onHold . ' ' . __('orders on hold');
        }

        return $parts === [] ? null : implode(self::SEP, $parts);
    }

    private function transferRow(array $transfer): PanelRow
    {
        $title = $this->join([$this->date($this->str($transfer, 'at')), $this->money($transfer, 'amount')]);
        $logId = $this->id($transfer, 'log_id');
        $orderId = $this->id($transfer, 'order_id');
        $matched = $this->str($transfer, 'status') === 'matched' || $orderId > 0;

        $name = $this->str($transfer, 'from_name');
        $reference = $this->str($transfer, 'reference');
        $lines = [];
        $detail = $this->join([
            $name === '' ? '' : __('from') . ' ' . $name,
            $reference === '' ? '' : __('ref') . ' ' . $reference,
        ]);
        if ($detail !== '') {
            $lines[] = $detail;
        }
        if ($orderId > 0) {
            $lines[] = '→ ' . __('order') . ' #' . $orderId;
        }

        $actions = [];
        if (!$matched && $logId > 0) {
            $actions[] = new RowAction($this->key(), 'match', __('Match to order'), ['log_id' => $logId]);
        }

        return new PanelRow(
            $title === '' ? __('e-Transfer') : $title,
            $lines,
            $matched ? __('Matched') : __('Unmatched'),
            $matched ? 'ok' : 'bad',
            $actions
        );
    }

    private function orderRow(array $order): ?PanelRow
    {
        $orderId = $this->id($order, 'order_id');
        if ($orderId <= 0) {
            return null;
        }

        $lines = [];
        $actions = [];
        foreach ($this->arr($order, 'possible_matches') as $match) {
            $logId = is_array($match) ? $this->id($match, 'log_id') : 0;
            if ($logId <= 0) {
                continue;
            }
            $lines[] = $this->possibleMatchLine($match);
            $actions[] = new RowAction(
                $this->key(),
                'match',
                __('Match'),
                ['log_id' => $logId, 'order_id' => $orderId]
            );
        }
        $actions[] = new RowAction($this->key(), 'match', __('Match a transfer…'), ['order_id' => $orderId]);

        $total = $this->money($order, 'total');

        return new PanelRow(
            __('Order') . ' #' . $orderId . self::SEP . __('on hold'),
            $lines,
            $total === '' ? null : $total,
            'warn',
            $actions
        );
    }

    private function possibleMatchLine(array $match): string
    {
        $line = __('Possible match:');
        $what = $this->join([$this->date($this->str($match, 'at')), $this->money($match, 'amount')]);
        if ($what !== '') {
            $line .= ' ' . $what;
        }
        $name = $this->str($match, 'from_name');
        if ($name !== '') {
            $line .= ' ' . __('from') . ' ' . $name;
        }
        $reference = $this->str($match, 'reference');
        if ($reference !== '') {
            $line .= ' (' . __('ref') . ' ' . $reference . ')';
        }

        return $line;
    }

    /**
     * @param string[] $parts
     */
    private function join(array $parts): string
    {
        return implode(self::SEP, array_filter($parts, 'strlen'));
    }

    private function money(array $data, string $key): string
    {
        $value = $this->str($data, $key);

        return is_numeric($value) ? '$' . number_format((float) $value, 2, '.', '') : '';
    }

    private function date(string $iso): string
    {
        if ($iso === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('M j');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @return int A positive id, or 0 when the value is missing or not one.
     */
    private function id(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private function arr(array $data, string $key): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
    }

    private function str(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
    }
}
