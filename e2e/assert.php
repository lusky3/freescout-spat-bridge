<?php

/**
 * End-to-end assertions, run inside FreeScout through `php artisan tinker`
 * by e2e/run-e2e.sh. Prints one `PASS <name>` or `FAIL <name>: <detail>`
 * line per check.
 *
 * SPATBRIDGE_E2E_PHASE selects the checks:
 *   main     ping_ok, wrong_secret_names_cause, sidebar_renders_player_card
 *            (WordPress up)
 *   wp_down  wp_down_shows_unavailable (run-e2e.sh has stopped WordPress)
 */

use Modules\SpatBridge\Services\ConnectionMessage;
use Modules\SpatBridge\Services\SpatClient;

$spatbridgeE2e = new class {
    // Mixed case on purpose: WordPress stores the lower-case address, and
    // the player must still be found.
    const CUSTOMER_EMAIL = 'Fixture-Bridge@Example.test';
    const MAILBOX_EMAIL = 'spatbridge-e2e@example.test';
    const SECRET_OPTION = 'spatbridge.shared_secret';

    public function run(string $phase): void
    {
        if ($phase === 'wp_down') {
            $this->check('wp_down_shows_unavailable', function () {
                return $this->wpDownShowsUnavailable();
            });

            return;
        }

        $this->check('ping_ok', function () {
            return $this->pingOk();
        });
        $this->check('wrong_secret_names_cause', function () {
            return $this->wrongSecretNamesCause();
        });
        $this->check('sidebar_renders_player_card', function () {
            return $this->sidebarRendersPlayerCard();
        });
    }

    /**
     * @param callable $body returns '' on success, otherwise the failure detail
     */
    private function check(string $name, callable $body): void
    {
        try {
            $detail = $body();
        } catch (\Throwable $e) {
            $detail = get_class($e) . ': ' . $e->getMessage();
        }

        echo ($detail === '' ? 'PASS ' . $name : 'FAIL ' . $name . ': ' . $this->oneLine($detail)) . "\n";
    }

    private function pingOk(): string
    {
        $result = app(SpatClient::class)->ping();
        if (!$result->ok()) {
            return 'ping failed (' . $result->errorCode() . '): ' . ConnectionMessage::describe($result);
        }

        $providers = $result->data()['providers'] ?? null;
        if (!is_array($providers)) {
            return 'no providers list in ' . json_encode($result->data());
        }
        $missing = array_diff(['player', 'registration'], $providers);
        if ($missing !== []) {
            return 'providers missing ' . implode(', ', $missing) . ' (got ' . implode(', ', $providers) . ')';
        }

        return '';
    }

    private function wrongSecretNamesCause(): string
    {
        $secret = (string) \Option::get(self::SECRET_OPTION, '');
        if ($secret === '') {
            return 'no shared secret configured';
        }

        try {
            $this->setOption(self::SECRET_OPTION, 'wrong-' . $secret);
            $result = app(SpatClient::class)->ping();
        } finally {
            $this->setOption(self::SECRET_OPTION, $secret);
        }

        if ($result->errorCode() !== 'bad_signature') {
            return 'expected error bad_signature, got "' . $result->errorCode() . '"';
        }
        $message = ConnectionMessage::describe($result);
        if (stripos($message, 'secret') === false) {
            return 'message does not mention the secret: ' . $message;
        }
        if (app(SpatClient::class)->ping()->ok() !== true) {
            return 'ping still fails after restoring the secret';
        }

        return '';
    }

    private function sidebarRendersPlayerCard(): string
    {
        list($mailbox, $customer, $conversation) = $this->fixtures(true);

        // Exercise the case-insensitive lookup for real: FreeScout lower-cases
        // addresses on create, so the mixed-case form is written back here.
        if ($customer->getMainEmail() !== self::CUSTOMER_EMAIL) {
            return 'customer email is "' . $customer->getMainEmail() . '", expected ' . self::CUSTOMER_EMAIL;
        }

        $this->forgetCachedCustomer();
        $html = $this->renderSidebar($customer, $conversation, $mailbox);

        $expected = [
            '#17 Jordan Smith (C)',
            'Riley Smith',
            'Ducks',
            'vs Otters',
            'registration not completed',
            'spatbridge-stacked',
            'spatbridge-tabbed',
        ];
        $missing = [];
        foreach ($expected as $needle) {
            if (strpos($html, $needle) === false) {
                $missing[] = '"' . $needle . '"';
            }
        }
        if ($missing !== []) {
            return 'sidebar is missing ' . implode(', ', $missing) . '; rendered text: ' . $this->text($html);
        }

        return '';
    }

    private function wpDownShowsUnavailable(): string
    {
        list($mailbox, $customer, $conversation) = $this->fixtures(false);
        if ($customer === null) {
            return 'no fixture customer; the main phase must run first';
        }

        $this->forgetCachedCustomer();
        try {
            $html = $this->renderSidebar($customer, $conversation, $mailbox);
        } catch (\Throwable $e) {
            return 'rendering threw ' . get_class($e) . ': ' . $e->getMessage();
        }

        if (strpos($html, 'League info unavailable') === false) {
            return 'sidebar does not say "League info unavailable"; rendered text: ' . $this->text($html);
        }
        if (strpos($html, 'Jordan Smith') !== false) {
            return 'sidebar still shows player data while WordPress is down';
        }

        return '';
    }

    /**
     * The mailbox, customer and conversation the sidebar is rendered for.
     * Re-runs reuse the rows created on the first run.
     *
     * @return array{0: ?\App\Mailbox, 1: ?\App\Customer, 2: ?\App\Conversation}
     */
    private function fixtures(bool $create): array
    {
        $mailbox = \App\Mailbox::where('email', self::MAILBOX_EMAIL)->first();
        $email = \App\Email::where('email', self::CUSTOMER_EMAIL)->first();
        $customer = $email ? \App\Customer::find($email->customer_id) : null;

        if (!$create) {
            $conversation = $customer
                ? \App\Conversation::where('customer_id', $customer->id)->first()
                : null;

            return [$mailbox, $customer, $conversation];
        }

        if (!$mailbox) {
            $mailbox = new \App\Mailbox();
            $mailbox->name = 'SpatBridge E2E';
            $mailbox->email = self::MAILBOX_EMAIL;
            $mailbox->save();
        }

        if (!$customer) {
            $customer = \App\Customer::create(self::CUSTOMER_EMAIL, ['first_name' => 'Jordan', 'last_name' => 'Smith']);
        }
        \App\Email::where('customer_id', $customer->id)->update(['email' => self::CUSTOMER_EMAIL]);
        $customer = \App\Customer::find($customer->id);

        $conversation = \App\Conversation::where('mailbox_id', $mailbox->id)
            ->where('customer_id', $customer->id)
            ->first();
        if (!$conversation) {
            $conversation = new \App\Conversation();
            $conversation->type = \App\Conversation::TYPE_EMAIL;
            $conversation->subject = 'Which team am I on?';
            $conversation->preview = '';
            $conversation->mailbox_id = $mailbox->id;
            $conversation->customer_id = $customer->id;
            $conversation->customer_email = self::CUSTOMER_EMAIL;
            $conversation->status = \App\Conversation::STATUS_ACTIVE;
            $conversation->state = \App\Conversation::STATE_PUBLISHED;
            $conversation->source_via = \App\Conversation::PERSON_CUSTOMER;
            $conversation->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
            $conversation->created_by_customer_id = $customer->id;
            $conversation->number = (int) \App\Conversation::max('number') + 1;
            $conversation->updateFolder($mailbox);
            $conversation->save();
        }

        return [$mailbox, $customer, $conversation];
    }

    private function renderSidebar($customer, $conversation, $mailbox): string
    {
        ob_start();
        try {
            \Eventy::action('conversation.after_prev_convs', $customer, $conversation, $mailbox);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function forgetCachedCustomer(): void
    {
        \Cache::forget('spatbridge.customer.' . md5(strtolower(self::CUSTOMER_EMAIL)));
    }

    /**
     * Option::set() leaves Option::get()'s per-process cache stale, so drop
     * the cached value for the client to read the new one.
     */
    private function setOption(string $key, string $value): void
    {
        \Option::set($key, $value);
        unset(\Option::$cache[$key]);
    }

    private function text(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        return $text === '' ? '(empty)' : mb_substr($text, 0, 400);
    }

    private function oneLine(string $detail): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $detail));
    }
};

$spatbridgeE2e->run((string) (getenv('SPATBRIDGE_E2E_PHASE') ?: 'main'));
