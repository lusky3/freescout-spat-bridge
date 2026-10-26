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
            $row = $this->row($entry, $status);
            $expired = $expired || $row->tone() === 'bad';
            $rows[] = $row;
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
            $what = $this->join([
                $this->str($offered, 'season'),
                $this->position($this->str($offered, 'position')),
            ], ' ');
            $expires = $this->dateTime($this->str($offered, 'expires_at'));

            return $expires === ''
                ? sprintf(__('Offered %s'), $what)
                : sprintf(__('Offered %s, expires %s'), $what, $expires);
        }

        if ($queued !== null && $this->str($queued, 'season') !== '') {
            return sprintf(__('On the %s waitlist'), $this->str($queued, 'season'));
        }

        return null;
    }

    private function row(array $entry, string $status): PanelRow
    {
        $title = $this->join([
            $this->str($entry, 'season'),
            $this->position($this->str($entry, 'position')),
        ], self::SEP);

        $badge = __('On waitlist');
        $tone = 'muted';
        $live = '';
        switch ($status) {
            case 'offered':
                $badge = __('Offer sent');
                $tone = 'warn';
                $expiresAt = $this->str($entry, 'expires_at');
                $target = $expiresAt === '' ? false : strtotime($expiresAt);
                if ($target !== false && $target <= time()) {
                    $badge = __('Offer expired');
                    $tone = 'bad';
                } elseif ($expiresAt !== '') {
                    // An unparseable deadline is shown as sent, not as passed.
                    $live = __('Expires') . ' ' . ($target === false ? $expiresAt : $this->relative($target));
                }
                break;
            case 'expired':
                $badge = __('Offer expired');
                $tone = 'bad';
                break;
            case 'claimed':
                $badge = __('Accepted');
                $tone = 'ok';
                break;
        }

        $lines = [];
        if ($live !== '') {
            $lines[] = $live;
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

    /**
     * Same thresholds and rounding as SplmWaitlist's EntryRenderer.
     */
    private function relative(int $target): string
    {
        $diff = $target - time();
        if ($diff <= 0) {
            return __('soon');
        }
        $hours = (int) round($diff / 3600);
        if ($hours < 1) {
            return __('in less than an hour');
        }
        if ($hours < 24) {
            return sprintf($hours === 1 ? __('in %d hour') : __('in %d hours'), $hours);
        }
        $days = (int) round($hours / 24);

        return sprintf($days === 1 ? __('in %d day') : __('in %d days'), $days);
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
