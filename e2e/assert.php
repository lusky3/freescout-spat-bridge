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
 *   actions  etransfers_panel, action_preview_mismatch, action_forbidden_actor,
 *            action_match_runs, action_permission_denied (WordPress up again)
 *
 * SPATBRIDGE_E2E_FIXTURES holds the JSON line the WordPress fixture script
 * printed (e-Transfer log ids, the on-hold order id).
 *
 * The action checks call ActionController's preview() and run() as a given
 * FreeScout user, preview first and then run with the preview's idempotency
 * key, the same way the pop-up does.
 */

use Illuminate\Http\Request;
use Modules\SpatBridge\Http\Controllers\ActionController;
use Modules\SpatBridge\Services\ActionPermissions;
use Modules\SpatBridge\Services\ConnectionMessage;
use Modules\SpatBridge\Services\SpatClient;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;

$spatbridgeE2e = new class {
    // Mixed case on purpose: WordPress stores the lower-case address, and
    // the player must still be found.
    const CUSTOMER_EMAIL = 'Fixture-Bridge@Example.test';
    const MAILBOX_EMAIL = 'spatbridge-e2e@example.test';
    const SECRET_OPTION = 'spatbridge.shared_secret';
    // Both FreeScout users exist on the WordPress side with the same email:
    // the admin as an administrator, nocap as a subscriber.
    const ADMIN_EMAIL = 'admin@example.test';
    const NOCAP_EMAIL = 'nocap@example.test';
    const ACTORS_OPTION = 'spatbridge.features.etransfers.actors';
    const NOTE_MARK = 'via ARL League';

    public function run(string $phase): void
    {
        if ($phase === 'wp_down') {
            $this->check('wp_down_shows_unavailable', function () {
                return $this->wpDownShowsUnavailable();
            });

            return;
        }

        if ($phase === 'actions') {
            // Order matters: action_match_runs takes the order off hold.
            $this->check('etransfers_panel', function () {
                return $this->etransfersPanel();
            });
            $this->check('action_preview_mismatch', function () {
                return $this->actionPreviewMismatch();
            });
            $this->check('action_forbidden_actor', function () {
                return $this->actionForbiddenActor();
            });
            $this->check('action_match_runs', function () {
                return $this->actionMatchRuns();
            });
            $this->check('action_permission_denied', function () {
                return $this->actionPermissionDenied();
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

    private function etransfersPanel(): string
    {
        list($mailbox, $customer, $conversation) = $this->fixtures(true);
        $orderId = $this->fixtureId('on_hold_order_id');

        $this->forgetCachedCustomer();
        \Auth::setUser($this->agent(self::ADMIN_EMAIL, $mailbox));
        $html = $this->renderSidebar($customer, $conversation, $mailbox);

        $missing = [];
        foreach (['Unmatched', 'FIXTURE-MISMATCH', 'Order #' . $orderId . ' · on hold', 'Possible match'] as $needle) {
            if (strpos($html, $needle) === false) {
                $missing[] = '"' . $needle . '"';
            }
        }
        if ($missing !== []) {
            return 'sidebar is missing ' . implode(', ', $missing) . '; rendered text: ' . $this->text($html);
        }

        return '';
    }

    private function actionPreviewMismatch(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $params = ['log_id' => $this->fixtureId('mismatch_log_id'), 'order_id' => $this->fixtureId('on_hold_order_id')];

        $html = $this->preview($admin, $conversation, $params);
        if ($this->idempotencyKey($html) === '') {
            return 'no preview for the admin; rendered text: ' . $this->text($html);
        }
        if (strpos($html, 'value="amount_mismatch"') === false) {
            return 'the preview does not ask for the amount_mismatch confirmation; rendered text: ' . $this->text($html);
        }

        return '';
    }

    private function actionForbiddenActor(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $nocap = $this->agent(self::NOCAP_EMAIL, $mailbox);
        $params = ['log_id' => $this->fixtureId('mismatch_log_id'), 'order_id' => $this->fixtureId('on_hold_order_id')];

        // FreeScout lets nocap act, so the refusal must come from WordPress.
        $this->setOption(self::ACTORS_OPTION, (string) json_encode(['mode' => 'selected', 'user_ids' => [(int) $nocap->id]]));

        $direct = app(SpatClient::class)->action('etransfers', 'match', $params, true, 'e2e-forbidden-' . bin2hex(random_bytes(8)), self::NOCAP_EMAIL, 'No Cap');
        if ($direct->errorCode() !== 'actor_forbidden') {
            return 'WordPress answered "' . $direct->errorCode() . '" (HTTP ' . $direct->status() . '), expected actor_forbidden';
        }

        $html = $this->preview($nocap, $conversation, $params);
        if ($this->idempotencyKey($html) !== '') {
            return 'nocap got a preview it may not run';
        }
        $expected = ConnectionMessage::actionMessage($direct);
        if (strpos($this->plain($html), $expected) === false) {
            return 'the preview does not say "' . $expected . '"; rendered text: ' . $this->text($html);
        }

        return '';
    }

    private function actionMatchRuns(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $logId = $this->fixtureId('etransfer_log_id');
        $orderId = $this->fixtureId('on_hold_order_id');
        $params = ['log_id' => $logId, 'order_id' => $orderId];
        $notesBefore = $this->noteCount($conversation);

        $html = $this->preview($admin, $conversation, $params);
        $key = $this->idempotencyKey($html);
        if ($key === '') {
            return 'no preview for the admin; rendered text: ' . $this->text($html);
        }

        // Run only runs what was previewed: neither a confirmation the
        // preview didn't ask for nor other params get through.
        $response = $this->runAction($admin, $conversation, $params, $key, ['amount_mismatch']);
        if ($response->getStatusCode() !== 409) {
            return 'a confirmation the preview did not ask for was accepted: HTTP ' . $response->getStatusCode() . ' ' . $response->getContent();
        }
        $other = ['log_id' => $this->fixtureId('mismatch_log_id'), 'order_id' => $orderId];
        $response = $this->runAction($admin, $conversation, $other, $key, []);
        if ($response->getStatusCode() !== 409) {
            return 'params that differ from the preview were accepted: HTTP ' . $response->getStatusCode() . ' ' . $response->getContent();
        }

        $response = $this->runAction($admin, $conversation, $params, $key, []);
        $body = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() !== 200 || ($body['status'] ?? null) !== 'success') {
            return 'run failed: HTTP ' . $response->getStatusCode() . ' ' . $response->getContent();
        }
        if ($this->noteCount($conversation) !== $notesBefore + 1) {
            return 'expected one new note containing "' . self::NOTE_MARK . '", found ' . ($this->noteCount($conversation) - $notesBefore);
        }

        $customer = app(SpatClient::class)->customer(self::CUSTOMER_EMAIL);
        if (!$customer->ok()) {
            return 'customer() failed after the run: ' . $customer->errorCode();
        }
        $section = $customer->data()['sections']['etransfers'] ?? [];
        foreach ($section['on_hold_orders'] ?? [] as $order) {
            if ((int) ($order['order_id'] ?? 0) === $orderId) {
                return 'order #' . $orderId . ' is still on hold';
            }
            foreach ($order['possible_matches'] ?? [] as $match) {
                if ((int) ($match['log_id'] ?? 0) === $logId) {
                    return 'e-Transfer ' . $logId . ' is still offered as a possible match';
                }
            }
        }
        foreach ($section['transfers'] ?? [] as $transfer) {
            if ((int) ($transfer['log_id'] ?? 0) === $logId && ($transfer['status'] ?? '') === 'unmatched') {
                return 'e-Transfer ' . $logId . ' is still unmatched';
            }
        }

        // The same key again, as a double-clicked Confirm would send it.
        $response = $this->runAction($admin, $conversation, $params, $key, []);
        $body = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() !== 200 || ($body['status'] ?? null) !== 'success') {
            return 'the repeated run failed: HTTP ' . $response->getStatusCode() . ' ' . $response->getContent();
        }
        if ($this->noteCount($conversation) !== $notesBefore + 1) {
            return 'the repeated run added a second note';
        }
        $replay = app(SpatClient::class)->action('etransfers', 'match', $params, false, $key, self::ADMIN_EMAIL, 'Admin User');
        if (!$replay->ok() || empty($replay->data()['replayed'])) {
            return 'WordPress did not replay the key: ' . json_encode($replay->data()) . ' ' . $replay->errorCode();
        }

        return '';
    }

    private function actionPermissionDenied(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $nocap = $this->agent(self::NOCAP_EMAIL, $mailbox);
        $params = ['log_id' => $this->fixtureId('mismatch_log_id'), 'order_id' => $this->fixtureId('on_hold_order_id')];

        // "admins" is also the default, so first let nocap in and check the
        // stored mode is honoured; the refusal below then comes from the
        // explicit "admins" setting, not from the fallback.
        $permissions = new ActionPermissions(app(OptionStoreInterface::class));
        $this->setOption(self::ACTORS_OPTION, (string) json_encode(['mode' => 'selected', 'user_ids' => [(int) $nocap->id]]));
        if (!$permissions->allows($nocap, 'etransfers')) {
            return 'the stored "selected" mode does not let nocap in';
        }
        $this->setOption(self::ACTORS_OPTION, (string) json_encode(['mode' => 'admins', 'user_ids' => []]));
        if ($permissions->mode('etransfers') !== 'admins' || $permissions->allows($nocap, 'etransfers')) {
            return 'the stored "admins" mode is not read back';
        }

        // A forged Run: nocap never saw a preview, and FreeScout must refuse
        // before anything reaches WordPress.
        $response = $this->runAction($nocap, $conversation, $params, 'e2e-forged-' . bin2hex(random_bytes(8)), []);
        if ($response->getStatusCode() !== 403) {
            return 'expected HTTP 403, got ' . $response->getStatusCode() . ' ' . $response->getContent();
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

    /**
     * The FreeScout user with this email, with access to the e2e mailbox.
     * run-e2e.sh creates both users.
     */
    private function agent(string $email, $mailbox): \App\User
    {
        $user = \App\User::where('email', $email)->first();
        if (!$user) {
            throw new \RuntimeException('no FreeScout user ' . $email);
        }
        $mailbox->users()->syncWithoutDetaching([$user->id]);

        return $user;
    }

    /**
     * ActionController::preview() as $user, as the pop-up loads it.
     */
    private function preview(\App\User $user, $conversation, array $params): string
    {
        $request = $this->request($user, 'GET', [
            'feature' => 'etransfers',
            'action' => 'match',
            'params' => json_encode($params),
            'conversation_id' => $conversation->id,
        ]);

        return (string) app(ActionController::class)->preview($request)->getContent();
    }

    /**
     * ActionController::run() as $user, as the pop-up's Confirm posts it.
     *
     * @param string[] $confirm
     */
    private function runAction(\App\User $user, $conversation, array $params, string $key, array $confirm): \Illuminate\Http\JsonResponse
    {
        $request = $this->request($user, 'POST', [
            'feature' => 'etransfers',
            'action' => 'match',
            'params' => json_encode($params),
            'conversation_id' => $conversation->id,
            'idempotency_key' => $key,
            'confirm' => $confirm,
        ]);

        return app(ActionController::class)->run($request);
    }

    private function request(\App\User $user, string $method, array $input): Request
    {
        \Auth::setUser($user);
        $request = Request::create('/spatbridge/action', $method, $input);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    /**
     * The key a rendered preview hands to Confirm; '' when the pop-up shows
     * an error instead.
     */
    private function idempotencyKey(string $html): string
    {
        return preg_match('/data-idempotency-key="([A-Za-z0-9-]+)"/', $html, $m) ? $m[1] : '';
    }

    private function noteCount($conversation): int
    {
        return \App\Thread::where('conversation_id', $conversation->id)
            ->where('type', \App\Thread::TYPE_NOTE)
            ->where('body', 'like', '%' . self::NOTE_MARK . '%')
            ->count();
    }

    private function fixtureId(string $key): int
    {
        $fixtures = json_decode((string) getenv('SPATBRIDGE_E2E_FIXTURES'), true);
        $id = is_array($fixtures) && isset($fixtures[$key]) ? (int) $fixtures[$key] : 0;
        if ($id <= 0) {
            throw new \RuntimeException('SPATBRIDGE_E2E_FIXTURES has no ' . $key);
        }

        return $id;
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
        $text = $this->plain($html);

        return $text === '' ? '(empty)' : mb_substr($text, 0, 400);
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function oneLine(string $detail): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $detail));
    }
};

$spatbridgeE2e->run((string) (getenv('SPATBRIDGE_E2E_PHASE') ?: 'main'));
