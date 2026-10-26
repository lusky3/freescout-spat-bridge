<?php

namespace Modules\SpatBridge\Services;

/**
 * The events WordPress sends: their types, default tag rules, and the copy
 * for the internal note and for the subject of a ticket the bridge creates.
 * Everything from WordPress is escaped in notes; subjects are plain text
 * (FreeScout escapes them when it shows them).
 */
final class EventCatalog
{
    private const UNKNOWN_SENDER = 'Unknown sender';
    public const SUBJECT_MAX = 200;

    /** @var array<string, array{add?: string, remove?: string}> */
    private const TAGS = [
        'etransfer.unmatched' => ['add' => 'payment-unmatched'],
        'etransfer.matched' => ['remove' => 'payment-unmatched'],
        'registration.incomplete' => ['add' => 'registration-issue'],
        'registration.reversed' => ['add' => 'registration-issue'],
        'waitlist.offered' => ['add' => 'waitlist-offered'],
        'waitlist.expired' => ['add' => 'waitlist-expired', 'remove' => 'waitlist-offered'],
        'waitlist.claimed' => ['remove' => 'waitlist-offered'],
    ];

    /**
     * The type whose tag a type removes, so the removal follows that type's
     * tag when an admin renames it.
     *
     * @var array<string, string>
     */
    private const REMOVES_TAG_OF = [
        'etransfer.matched' => 'etransfer.unmatched',
        'waitlist.expired' => 'waitlist.offered',
        'waitlist.claimed' => 'waitlist.offered',
    ];

    /**
     * @return string[]
     */
    public function types(): array
    {
        return array_keys(self::TAGS);
    }

    public function has(string $type): bool
    {
        return isset(self::TAGS[$type]);
    }

    /**
     * @return array{add?: string, remove?: string}|null
     */
    public function defaultTag(string $type): ?array
    {
        return self::TAGS[$type] ?? null;
    }

    public function removesTagOf(string $type): ?string
    {
        return self::REMOVES_TAG_OF[$type] ?? null;
    }

    public function noteHtml(string $type, array $data): string
    {
        return '<p>' . self::escape($this->sentence($type, $data)) . '</p>'
            . '<p><small>' . self::escape(__('via ARL League (event)')) . '</small></p>';
    }

    /**
     * At most SUBJECT_MAX characters, whatever WordPress sends.
     */
    public function subject(string $type, array $data): string
    {
        return mb_substr($this->fullSubject($type, $data), 0, self::SUBJECT_MAX);
    }

    private function fullSubject(string $type, array $data): string
    {
        $order = $this->text($data, 'order_id');
        $season = trim($this->text($data, 'season') . ' ' . $this->text($data, 'position'));

        switch ($type) {
            case 'etransfer.unmatched':
                return strtr(__('Unmatched e-Transfer: :name, :amount'), [
                    ':name' => $this->sender($data),
                    ':amount' => $this->amount($data),
                ]);
            case 'etransfer.matched':
                return strtr(__('e-Transfer matched: order #:order'), [':order' => $order]);
            case 'registration.incomplete':
                return strtr(__('Registration not completed: order #:order'), [':order' => $order]);
            case 'registration.reversed':
                return strtr(__('Registration reversed: order #:order'), [':order' => $order]);
            case 'waitlist.offered':
                return strtr(__('Waitlist offer: :season'), [':season' => $season]);
            case 'waitlist.expired':
                return strtr(__('Waitlist offer expired: :season'), [':season' => $season]);
            case 'waitlist.claimed':
                return strtr(__('Waitlist offer claimed: :season'), [':season' => $season]);
        }

        return $type;
    }

    /**
     * The sender's name for an e-Transfer, or "Unknown sender".
     */
    public function sender(array $data): string
    {
        $name = $this->text($data, 'from_name');

        return $name === '' ? __(self::UNKNOWN_SENDER) : $name;
    }

    private function sentence(string $type, array $data): string
    {
        $order = $this->text($data, 'order_id');
        $season = $this->waitlistSeason($data);

        switch ($type) {
            case 'etransfer.unmatched':
                return strtr(__('Unmatched e-Transfer: :amount from :name:ref.'), [
                    ':amount' => $this->amount($data),
                    ':name' => $this->sender($data),
                    ':ref' => $this->reference($data),
                ]);
            case 'etransfer.matched':
                $template = empty($data['manual'])
                    ? __('e-Transfer matched to order #:order: :amount:ref.')
                    : __('e-Transfer matched to order #:order by hand: :amount:ref.');

                return strtr($template, [
                    ':order' => $order,
                    ':amount' => $this->amount($data),
                    ':ref' => $this->reference($data),
                ]);
            case 'registration.incomplete':
                return strtr(__('Registration for order #:order did not complete: :outcome.'), [
                    ':order' => $order,
                    ':outcome' => str_replace('_', ' ', $this->text($data, 'outcome')),
                ]);
            case 'registration.reversed':
                return strtr(__('Registration for order #:order was reversed.'), [':order' => $order]);
            case 'waitlist.offered':
                $expires = $this->expiry($this->text($data, 'expires_at'));
                $template = $expires === ''
                    ? __('Waitlist offer sent for :season.')
                    : __('Waitlist offer sent for :season, expires :expires.');

                return strtr($template, [':season' => $season, ':expires' => $expires]);
            case 'waitlist.expired':
                return strtr(__('Waitlist offer for :season expired.'), [':season' => $season]);
            case 'waitlist.claimed':
                $template = $order === ''
                    ? __('Waitlist offer for :season claimed.')
                    : __('Waitlist offer for :season claimed with order #:order.');

                return strtr($template, [':season' => $season, ':order' => $order]);
        }

        return $type;
    }

    /**
     * "S2026 (Goalie)", or just "S2026" without a position.
     */
    private function waitlistSeason(array $data): string
    {
        $season = $this->text($data, 'season');
        $position = $this->text($data, 'position');

        return $position === '' ? $season : trim($season . ' (' . $position . ')');
    }

    private function amount(array $data): string
    {
        $amount = $this->text($data, 'amount');

        return is_numeric($amount) ? '$' . number_format((float) $amount, 2) : $amount;
    }

    private function reference(array $data): string
    {
        $reference = $this->text($data, 'reference');

        return $reference === '' ? '' : strtr(__(' (ref :ref)'), [':ref' => $reference]);
    }

    /**
     * "Oct 27, 6:00 PM UTC" from a Unix time or an ISO/MySQL date. A date
     * without a zone is read as UTC; anything else is shown as sent.
     */
    private function expiry(string $value): string
    {
        if ($value === '' || !(ctype_digit($value) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value))) {
            return $value;
        }

        try {
            $utc = new \DateTimeZone('UTC');
            $date = new \DateTime(ctype_digit($value) ? '@' . $value : $value, $utc);
        } catch (\Exception $e) {
            return $value;
        }

        return $date->setTimezone($utc)->format('M j, g:i A') . ' UTC';
    }

    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
