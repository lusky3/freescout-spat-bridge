<?php

namespace Modules\SpatBridge\Features\Waitlist;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Features\PanelRow;
use Modules\SpatBridge\Features\RowAction;

/**
 * The customer's SportsPress waitlist entries, with Offer, Re-offer, Withdraw
 * offer and Remove actions that go through the preview and confirm pop-up.
 * Labels and detail lines match the retired SplmWaitlist module.
 *
 * Wire data comes from another system, so every key may be missing and every
 * value may have the wrong type; nothing here throws on that.
 */
final class WaitlistFeature implements Feature
{
    private const SEP = ' · ';
    private const OFFER_FORM_VIEW = 'spatbridge::action.waitlist_offer_form';
    private const DEFAULT_HOURS = 48;

    public function key(): string
    {
        return 'waitlist';
    }

    public function label(): string
    {
        return __('Waitlist');
    }

    public function providerKeys(): array
    {
        return ['waitlist'];
    }

    public function actionKeys(): array
    {
        return ['offer', 'reoffer', 'withdraw', 'remove'];
    }

    public function queryKeys(): array
    {
        return [];
    }

    public function present(array $sections): ?Panel
    {
        $section = $this->arr($sections, 'waitlist');

        $rows = [];
        $offered = null;
        $queued = null;
        $expired = false;
        foreach ($this->arr($section, 'entries') as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = $this->str($entry, 'status');
            if ($status === 'offered' && $offered === null) {
                $offered = $entry;
            }
            if ($status !== 'offered' && $status !== 'claimed' && $queued === null) {
                $queued = $entry;
            }
            $expired = $expired || $status === 'expired';
            $rows[] = $this->row($entry, $status);
        }

        if ($rows === []) {
            return null;
        }

        return new Panel($this->key(), $this->label(), $rows, $this->summary($offered, $queued), $expired);
    }

    public function previewOverride(string $action, array $params): ?array
    {
        if (($action !== 'offer' && $action !== 'reoffer') || array_key_exists('hours', $params)) {
            return null;
        }

        return ['view' => self::OFFER_FORM_VIEW, 'data' => ['defaultHours' => self::DEFAULT_HOURS]];
    }

    public function mapConfirmCodes(string $action, array $codes, array $params): array
    {
        return $params;
    }

    private function summary(?array $offered, ?array $queued): ?string
    {
        if ($offered !== null) {
            $line = __('Offered') . ' ' . $this->join([
                $this->str($offered, 'season'),
                $this->position($this->str($offered, 'position')),
            ], ' ');
            $expires = $this->dateTime($this->str($offered, 'expires_at'));

            return rtrim($line) . ($expires === '' ? '' : ', ' . __('expires') . ' ' . $expires);
        }

        if ($queued !== null && $this->str($queued, 'season') !== '') {
            return __('On the') . ' ' . $this->str($queued, 'season') . ' ' . __('waitlist');
        }

        return null;
    }

    private function row(array $entry, string $status): PanelRow
    {
        $title = $this->join([
            $this->str($entry, 'season'),
            $this->position($this->str($entry, 'position')),
        ], self::SEP);

        switch ($status) {
            case 'offered':
                $badge = __('Offered');
                $tone = 'warn';
                break;
            case 'expired':
                $badge = __('Offer expired');
                $tone = 'bad';
                break;
            case 'claimed':
                $badge = __('Accepted');
                $tone = 'ok';
                break;
            default:
                $badge = __('On waitlist');
                $tone = 'muted';
        }

        $lines = [];
        $expires = $status === 'offered' ? $this->dateTime($this->str($entry, 'expires_at')) : '';
        if ($expires !== '') {
            $lines[] = __('Offer expires') . ' ' . $expires;
        } else {
            $since = $this->date($this->str($entry, 'created_at'));
            if ($since !== '') {
                $lines[] = __('On waitlist since') . ' ' . $since;
            }
        }

        $hasTarget = !empty($entry['has_target']);
        if (!$hasTarget && ($status === 'queued' || $status === 'expired')) {
            $lines[] = __('No target product set (set it in WordPress before offering)');
        }

        return new PanelRow(
            $title === '' ? __('Waitlist') : $title,
            $lines,
            $badge,
            $tone,
            $this->actions($this->id($entry, 'id'), $status, $hasTarget)
        );
    }

    /**
     * @return RowAction[]
     */
    private function actions(int $id, string $status, bool $hasTarget): array
    {
        if ($id <= 0) {
            return [];
        }

        $params = ['entry_id' => $id];
        $actions = [];
        if (($status === 'queued' || $status === 'expired') && $hasTarget) {
            $actions[] = new RowAction($this->key(), 'offer', __('Offer…'), $params);
        }
        if ($status === 'offered') {
            $actions[] = new RowAction($this->key(), 'reoffer', __('Re-offer…'), $params);
            $actions[] = new RowAction($this->key(), 'withdraw', __('Withdraw offer'), $params);
        }
        if ($status === 'queued') {
            $actions[] = new RowAction($this->key(), 'remove', __('Remove from waitlist'), $params);
        }

        return $actions;
    }

    private function position(string $position): string
    {
        switch ($position) {
            case 'player':
                return __('Player');
            case 'goalie':
                return __('Goalie');
            default:
                return '';
        }
    }

    /**
     * @param string[] $parts
     */
    private function join(array $parts, string $separator): string
    {
        return implode($separator, array_filter($parts, 'strlen'));
    }

    private function date(string $iso): string
    {
        return $this->format($iso, 'M j');
    }

    /**
     * WordPress sends UTC, so say so rather than let it read as local time.
     */
    private function dateTime(string $iso): string
    {
        $formatted = $this->format($iso, 'M j, g:i A');

        return $formatted === '' ? '' : $formatted . ' UTC';
    }

    private function format(string $iso, string $format): string
    {
        if ($iso === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format($format);
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
