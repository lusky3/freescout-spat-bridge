<?php

namespace Modules\SpatBridge\Features\PlayerCard;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\GuardsActionParams;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Features\PanelRow;
use Modules\SpatBridge\Features\RowAction;

/**
 * Player card: one row per player on the customer's email, plus registration
 * rows that need an agent's attention and a short history. The first player
 * row offers Change email; each attention row offers Re-run registration.
 *
 * Wire data comes from another system, so every key may be missing and every
 * value may have the wrong type; nothing here throws on that.
 */
final class PlayerCardFeature implements Feature, GuardsActionParams
{
    private const SEP = ' · ';
    private const EMAIL_FORM_VIEW = 'spatbridge::action.change_email_form';
    private const ERROR_VIEW = 'spatbridge::action.error';

    public function key(): string
    {
        return 'player';
    }

    public function label(): string
    {
        return __('Player');
    }

    public function providerKeys(): array
    {
        return ['player', 'registration'];
    }

    public function present(array $sections): ?Panel
    {
        $playerSection = $this->arr($sections, 'player');
        $registration = $this->arr($sections, 'registration');
        $season = $this->str($playerSection, 'current_season');
        $email = $this->str($playerSection, 'email');

        $rows = [];
        $summaries = [];
        $attention = false;

        foreach ($this->arr($playerSection, 'players') as $player) {
            if (!is_array($player)) {
                continue;
            }
            $current = $this->currentAssignment($player);
            $rows[] = $this->playerRow($player, $current, $season, $rows === [] ? $email : '');
            $summaries[] = $this->playerSummary($player, $current);
            if ($current === null) {
                $attention = true;
            }
        }

        $needsAttention = false;
        foreach ($this->arr($registration, 'attention') as $item) {
            if (!is_array($item)) {
                continue;
            }
            $needsAttention = true;
            $rows[] = $this->attentionRow($item);
        }

        $history = $this->historyLines($this->arr($registration, 'entries'));
        if ($history !== []) {
            $rows[] = new PanelRow(__('Registration history'), $history);
        }

        if ($rows === []) {
            return null;
        }

        $summary = implode(', ', $summaries);
        if ($needsAttention) {
            $summary .= ($summary === '' ? '' : ' — ') . __('Registration needs attention');
        }

        return new Panel(
            $this->key(),
            $this->label(),
            $rows,
            $summary === '' ? null : $summary,
            $attention || $needsAttention
        );
    }

    public function actionKeys(): array
    {
        return ['change_email', 'rerun'];
    }

    public function queryKeys(): array
    {
        return [];
    }

    public function guardParams(string $action, array $params, array $customerEmails): ?string
    {
        if ($action !== 'change_email') {
            return null;
        }

        // The old address is browser input; only this ticket's customer may be changed.
        $old = strtolower($this->str($params, 'old_email'));
        foreach ($customerEmails as $email) {
            if ($old !== '' && is_string($email) && strtolower(trim($email)) === $old) {
                return null;
            }
        }

        return __("That email address isn't this ticket's customer's.");
    }

    public function previewOverride(string $action, array $params): ?array
    {
        if ($action !== 'change_email') {
            return null;
        }

        $old = $this->str($params, 'old_email');
        if (!array_key_exists('new_email', $params)) {
            return ['view' => self::EMAIL_FORM_VIEW, 'data' => ['oldEmail' => $old]];
        }

        // Checked here so a bad address never costs a WordPress call.
        $new = $params['new_email'];
        if (!is_string($new) || filter_var($new, FILTER_VALIDATE_EMAIL) === false) {
            return $this->emailError(__('Enter a valid new email address.'));
        }
        if (strcasecmp($new, $old) === 0) {
            return $this->emailError(__('The new email address is the same as the current one.'));
        }

        return null;
    }

    private function emailError(string $message): array
    {
        return ['view' => self::ERROR_VIEW, 'data' => ['message' => $message]];
    }

    public function mapConfirmCodes(string $action, array $codes, array $params): array
    {
        return $params;
    }

    private function playerRow(array $player, ?array $current, string $season, string $email): PanelRow
    {
        $title = $this->playerTitle($player);
        if ($current !== null && !empty($current['captain'])) {
            $title .= ' (C)';
        }

        $lines = [];
        if ($current !== null) {
            $lines[] = implode(self::SEP, array_filter([
                $this->str($current, 'season'),
                $this->str($current, 'division'),
                $this->str($current, 'team'),
            ], 'strlen'));
        } else {
            $lines[] = $season === ''
                ? __('Not on a roster')
                : __('Not on a roster for') . ' ' . $season;
        }

        $details = [];
        $positions = array_filter(array_map('strval', array_filter(
            $this->arr($player, 'positions'),
            'is_scalar'
        )), 'strlen');
        if ($positions !== []) {
            $details[] = implode(', ', $positions);
        }
        if ($this->str($player, 'skill_level') !== '') {
            $details[] = __('Skill') . ' ' . $this->str($player, 'skill_level');
        }
        if (!empty($player['wp_user_linked'])) {
            $details[] = __('WP account linked');
        }
        if ($details !== []) {
            $lines[] = implode(self::SEP, $details);
        }

        $game = $this->nextGameLine($this->arr($player, 'next_game'));
        if ($game !== '') {
            $lines[] = $game;
        }

        $actions = [];
        if ($email !== '') {
            $actions[] = new RowAction($this->key(), 'change_email', __('Change email…'), ['old_email' => $email]);
        }

        return new PanelRow($title, array_values(array_filter($lines, 'strlen')), null, 'muted', $actions);
    }

    private function playerTitle(array $player): string
    {
        $name = $this->str($player, 'name');
        $number = $this->str($player, 'number');

        return trim(($number !== '' ? '#' . $number . ' ' : '') . $name);
    }

    private function playerSummary(array $player, ?array $current): string
    {
        $team = $current === null ? '' : $this->str($current, 'team');

        return $this->playerTitle($player) . ($team !== '' ? self::SEP . $team : '');
    }

    private function currentAssignment(array $player): ?array
    {
        foreach ($this->arr($player, 'assignments') as $assignment) {
            if (is_array($assignment) && !empty($assignment['current'])) {
                return $assignment;
            }
        }

        return null;
    }

    private function nextGameLine(array $game): string
    {
        $when = $this->formatDate($this->str($game, 'start'), 'D M j, g:i A');
        if ($when === '') {
            return '';
        }

        $line = __('Next game') . ' ' . $when;
        $opponent = $this->str($game, 'opponent');
        if ($opponent !== '') {
            $line .= ' vs ' . $opponent;
        }
        $venue = $this->str($game, 'venue');
        if ($venue !== '') {
            $line .= self::SEP . $venue;
        }

        return $line;
    }

    private function attentionRow(array $item): PanelRow
    {
        $lines = [];
        $last = $this->str($item, 'last_action');
        if ($last !== '') {
            $lines[] = __('Last outcome:') . ' ' . str_replace('_', ' ', $last);
        }

        return new PanelRow(
            $this->orderLabel($item) . self::SEP . __('registration not completed'),
            $lines,
            __('Check'),
            'bad',
            $this->rerunActions($item)
        );
    }

    /**
     * @return RowAction[]
     */
    private function rerunActions(array $item): array
    {
        $id = $item['order_id'] ?? null;
        $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : 0);

        return $id > 0
            ? [new RowAction($this->key(), 'rerun', __('Re-run registration'), ['order_id' => $id])]
            : [];
    }

    /**
     * @return string[]
     */
    private function historyLines(array $entries): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $line = $this->orderLabel($entry);
            $action = str_replace('_', ' ', $this->str($entry, 'action'));
            if ($action !== '') {
                $line .= ' → ' . $action;
            }
            $date = $this->formatDate($this->str($entry, 'at'), 'M j');
            if ($date !== '') {
                $line .= self::SEP . $date;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private function orderLabel(array $item): string
    {
        $id = $this->str($item, 'order_id');

        return $id === '' ? __('Order') : __('Order') . ' #' . $id;
    }

    /**
     * Keeps the offset the timestamp carries; no conversion to server time.
     */
    private function formatDate(string $iso, string $format): string
    {
        if ($iso === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($iso))->format($format);
        } catch (\Exception $e) {
            return '';
        }
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
