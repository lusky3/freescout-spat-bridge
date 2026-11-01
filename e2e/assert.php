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
 *   waitlist_panel, waitlist_offer, waitlist_offer_after, waitlist_reoffer
 *            the waitlist panel and its actions
 *   change_email_refusals, change_email_run, rerun
 *            the Player card actions
 *   secondary_address
 *            a waitlist entry and a player under another of the customer's
 *            addresses (run-e2e.sh creates them in WordPress and removes
 *            them afterwards)
 *   events_received, events_create_setup, events_created, events_retried
 *            events WordPress sent (run-e2e.sh fires them and runs WP-Cron)
 *
 * Some checks span several phases because run-e2e.sh checks the WordPress
 * side (captured mail, emails, the outbox) between them. A step that passes
 * prints nothing; only the last step of a check prints PASS, and any step
 * may print FAIL. run-e2e.sh counts a check as passed when it has a PASS line
 * and no FAIL line.
 *
 * SPATBRIDGE_E2E_FIXTURES holds the JSON line the WordPress fixture script
 * printed (e-Transfer log ids, the on-hold order id, waitlist entry ids, the
 * change-email player). The event phases also get SPATBRIDGE_E2E_EVENT_ID,
 * SPATBRIDGE_E2E_EVENT_REF and SPATBRIDGE_E2E_ORDER_ID from run-e2e.sh, and
 * the secondary-address phase SPATBRIDGE_E2E_SECONDARY_EMAIL and
 * SPATBRIDGE_E2E_SECONDARY_PLAYER_ID.
 *
 * The action checks call ActionController's preview() and run() as a given
 * FreeScout user, preview first and then run with the preview's idempotency
 * key, the same way the pop-up does.
 */

use Illuminate\Http\Request;
use Modules\SpatBridge\Http\Controllers\ActionController;
use Modules\SpatBridge\Services\ActionPermissions;
use Modules\SpatBridge\Services\ConnectionMessage;
use Modules\SpatBridge\Services\Signer;
use Modules\SpatBridge\Services\SpatClient;
use Modules\SpatBridge\Services\Support\CustomerEmails;
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
    // Event notes end in "via ARL League (event)"; action counts skip them.
    const EVENT_NOTE_MARK = 'via ARL League (event)';
    // WordPress stores this one lower-case, and so does the sidebar.
    const WP_CUSTOMER_EMAIL = 'fixture-bridge@example.test';
    const CHANGE_EMAIL = 'change-me@example.test';
    const CHANGE_EMAIL_NEW = 'casey.new@example.test';
    const FOREIGN_NEW_EMAIL = 'foreign-new@example.test';
    const NEW_PERSON_EMAIL = 'new-person@example.test';
    // The receiver on the compose network, as WordPress posts to it.
    const EVENTS_URL = 'http://freescout-web/spatbridge/events';
    const CACHE_PREFIX = 'spatbridge-e2e.';

    public function run(string $phase): void
    {
        $phases = [
            'waitlist_panel' => function () {
                $this->check('waitlist_panel', function () {
                    return $this->waitlistPanel();
                });
            },
            'waitlist_offer' => function () {
                $this->step('waitlist_offer_flow', function () {
                    return $this->waitlistOffer();
                });
            },
            'waitlist_offer_after' => function () {
                $this->check('waitlist_offer_flow', function () {
                    return $this->waitlistOfferAfter();
                });
            },
            'waitlist_reoffer' => function () {
                $this->check('waitlist_reoffer', function () {
                    return $this->waitlistReoffer();
                });
                $this->check('waitlist_forbidden', function () {
                    return $this->waitlistForbidden();
                });
            },
            'change_email_refusals' => function () {
                $this->step('change_email_flow', function () {
                    return $this->changeEmailConflict();
                });
                $this->check('change_email_foreign_old_email', function () {
                    return $this->changeEmailForeignOldEmail();
                });
            },
            'change_email_run' => function () {
                $this->step('change_email_flow', function () {
                    return $this->changeEmailRun();
                });
            },
            'rerun' => function () {
                $this->check('rerun_registration', function () {
                    return $this->rerunRegistration();
                });
            },
            'secondary_address' => function () {
                $this->step('secondary_address_lookup', function () {
                    return $this->secondaryAddress();
                });
            },
            'events_received' => function () {
                $this->check('event_unmatched_notes_ticket', function () {
                    return $this->eventUnmatchedNoted();
                });
                $this->check('event_duplicate_ignored', function () {
                    return $this->eventDuplicateIgnored();
                });
            },
            'events_create_setup' => function () {
                $this->step('event_creates_ticket_when_enabled', function () {
                    return $this->eventCreateSetup();
                });
            },
            'events_created' => function () {
                $this->check('event_creates_ticket_when_enabled', function () {
                    return $this->eventCreated();
                });
            },
            'events_retried' => function () {
                $this->check('event_retry_after_freescout_down', function () {
                    return $this->eventRetried();
                });
            },
        ];
        if (isset($phases[$phase])) {
            $phases[$phase]();

            return;
        }

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
        $detail = $this->detailOf($body);

        echo ($detail === '' ? 'PASS ' . $name : 'FAIL ' . $name . ': ' . $this->oneLine($detail)) . "\n";
    }

    /**
     * Runs a check body; a thrown \UnexpectedValueException is a failure
     * detail, anything else thrown is reported with its class.
     */
    private function detailOf(callable $body): string
    {
        try {
            return (string) $body();
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }

    /**
     * One step of a check that continues in a later phase: prints
     * `STEP <name>` on success, so run-e2e.sh can tell a step that passed
     * from a phase that died before printing anything.
     *
     * @param callable $body returns '' on success, otherwise the failure detail
     */
    private function step(string $name, callable $body): void
    {
        $detail = $this->detailOf($body);
        echo ($detail === '' ? 'STEP ' . $name : 'FAIL ' . $name . ': ' . $this->oneLine($detail)) . "\n";
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
        list($mailbox, $fixtureCustomer, $conversation) = $this->fixtures(true);
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

        $customer = app(SpatClient::class)->customer(CustomerEmails::of($fixtureCustomer, $conversation));
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

    // ------------------------------------------------------------------
    // Waitlist
    // ------------------------------------------------------------------

    private function waitlistPanel(): string
    {
        $html = $this->fixtureSidebar();

        $queued = $this->row($html, 'S2026 · Player');
        $expired = $this->row($html, 'S2026 · Goalie');
        if ($queued === null || $expired === null) {
            return 'no rows "S2026 · Player" and "S2026 · Goalie"; rendered text: ' . $this->text($html);
        }
        if ($queued['badge'] !== 'On waitlist') {
            return 'the queued entry shows "' . $queued['badge'] . '", expected "On waitlist": ' . $queued['text'];
        }
        if ($expired['badge'] !== 'Offer expired') {
            return 'the expired entry shows "' . $expired['badge'] . '", expected "Offer expired": ' . $expired['text'];
        }

        return '';
    }

    /**
     * Preview and run an offer on the queued entry. run-e2e.sh then checks
     * WordPress captured exactly one email to the customer.
     */
    private function waitlistOffer(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $params = ['entry_id' => $this->fixtureId('waitlist_queued_id'), 'hours' => 48];

        $done = $this->previewAndRun($admin, $conversation, 'waitlist', 'offer', $params);
        $expected = 'Emails an offer to ' . self::WP_CUSTOMER_EMAIL;
        if (strpos($this->plain($done['preview']), $expected) === false) {
            return 'the preview does not say "' . $expected . '": ' . $this->text($done['preview']);
        }

        return '';
    }

    /**
     * The offered entry, then withdraw (back on the waitlist), then remove
     * (gone from the panel).
     */
    private function waitlistOfferAfter(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $id = $this->fixtureId('waitlist_queued_id');
        $title = 'S2026 · Player';

        $row = $this->row($this->fixtureSidebar(), $title);
        if ($row === null || $row['badge'] !== 'Offer sent' || strpos($row['text'], 'Expires in 2 days') === false) {
            return 'after the offer, expected "Offer sent" and "Expires in 2 days"; row: ' . ($row['text'] ?? '(none)');
        }

        $this->previewAndRun($admin, $conversation, 'waitlist', 'withdraw', ['entry_id' => $id]);
        $row = $this->row($this->fixtureSidebar(), $title);
        if ($row === null || $row['badge'] !== 'On waitlist') {
            return 'after withdraw, expected "On waitlist"; row: ' . ($row['text'] ?? '(none)');
        }

        $this->previewAndRun($admin, $conversation, 'waitlist', 'remove', ['entry_id' => $id]);
        $html = $this->fixtureSidebar();
        if ($this->row($html, $title) !== null) {
            return 'after remove, the entry is still in the panel: ' . $this->text($html);
        }

        return '';
    }

    /**
     * Offer the expired entry, then re-offer it with a longer window.
     * run-e2e.sh checks the two emails.
     */
    private function waitlistReoffer(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $id = $this->fixtureId('waitlist_expired_id');
        $title = 'S2026 · Goalie';

        $this->previewAndRun($admin, $conversation, 'waitlist', 'offer', ['entry_id' => $id, 'hours' => 48]);
        $row = $this->row($this->fixtureSidebar(), $title);
        if ($row === null || $row['badge'] !== 'Offer sent' || strpos($row['text'], 'Expires in 2 days') === false) {
            return 'after the offer, expected "Offer sent" and "Expires in 2 days"; row: ' . ($row['text'] ?? '(none)');
        }

        $done = $this->previewAndRun($admin, $conversation, 'waitlist', 'reoffer', ['entry_id' => $id, 'hours' => 72]);
        if (strpos($this->plain($done['preview']), 'Withdraws the current offer') === false) {
            return 'the re-offer preview does not say "Withdraws the current offer": ' . $this->text($done['preview']);
        }
        $row = $this->row($this->fixtureSidebar(), $title);
        if ($row === null || $row['badge'] !== 'Offer sent' || strpos($row['text'], 'Expires in 3 days') === false) {
            return 'after the re-offer, expected "Offer sent" and the new "Expires in 3 days"; row: ' . ($row['text'] ?? '(none)');
        }

        return '';
    }

    private function waitlistForbidden(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $nocap = $this->agent(self::NOCAP_EMAIL, $mailbox);
        $params = ['entry_id' => $this->fixtureId('waitlist_expired_id'), 'hours' => 48];

        // Waitlist actions default to every user, so FreeScout lets nocap in
        // and the refusal must come from WordPress.
        if (!(new ActionPermissions(app(OptionStoreInterface::class)))->allows($nocap, 'waitlist')) {
            return 'FreeScout does not let nocap run waitlist actions; the default should be "users"';
        }
        $direct = app(SpatClient::class)->action('waitlist', 'reoffer', $params, true, 'e2e-forbidden-' . bin2hex(random_bytes(8)), self::NOCAP_EMAIL, 'No Cap');
        if ($direct->errorCode() !== 'actor_forbidden') {
            return 'WordPress answered "' . $direct->errorCode() . '" (HTTP ' . $direct->status() . '), expected actor_forbidden';
        }

        $html = $this->previewAction($nocap, $conversation, 'waitlist', 'reoffer', $params);
        if ($this->idempotencyKey($html) !== '') {
            return 'nocap got a preview it may not run';
        }
        $expected = "Your WordPress account isn't allowed to do this.";
        if (strpos($this->plain($html), $expected) === false) {
            return 'the preview does not say "' . $expected . '"; rendered text: ' . $this->text($html);
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Player card actions
    // ------------------------------------------------------------------

    /**
     * Changing change-me to the fixture customer's address, which another
     * WordPress login holds, is refused. run-e2e.sh checks nothing changed.
     */
    private function changeEmailConflict(): string
    {
        list($mailbox, , $conversation) = $this->changeMeFixtures();
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $params = ['old_email' => self::CHANGE_EMAIL, 'new_email' => self::WP_CUSTOMER_EMAIL];
        // WordPress's message for email_in_use when the address is another login's.
        $expected = self::WP_CUSTOMER_EMAIL . ' already belongs to WordPress login #';

        $html = $this->previewAction($admin, $conversation, 'player', 'change_email', $params);
        if ($this->idempotencyKey($html) !== '') {
            return 'a change to an address in use got a preview: ' . $this->text($html);
        }
        if (strpos($this->plain($html), $expected) === false) {
            return 'the preview does not say "' . $expected . '…"; rendered text: ' . $this->text($html);
        }

        // The pop-up can't get past the preview, so ask WordPress for the
        // real (not dry) run directly: it must refuse too.
        $direct = app(SpatClient::class)->action('player', 'change_email', $params, false, 'e2e-in-use-' . bin2hex(random_bytes(8)), self::ADMIN_EMAIL, 'Admin User');
        if ($direct->ok() || $direct->status() !== 409 || strpos($direct->detail(), $expected) === false) {
            return 'a real run was not refused with email_in_use: HTTP ' . $direct->status() . ' ' . $direct->errorCode() . ' ' . $direct->detail();
        }

        return '';
    }

    /**
     * old_email is browser input: another customer's address is refused
     * before WordPress is asked. run-e2e.sh checks WordPress is unchanged.
     */
    private function changeEmailForeignOldEmail(): string
    {
        list($mailbox, , $conversation) = $this->changeMeFixtures();
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $params = ['old_email' => self::WP_CUSTOMER_EMAIL, 'new_email' => self::FOREIGN_NEW_EMAIL];
        $expected = "That email address isn't this ticket's customer's.";

        $html = $this->previewAction($admin, $conversation, 'player', 'change_email', $params);
        if ($this->idempotencyKey($html) !== '') {
            return 'a change of another customer\'s address got a preview: ' . $this->text($html);
        }
        if (strpos($this->plain($html), $expected) === false) {
            return 'the preview does not say "' . $expected . '"; rendered text: ' . $this->text($html);
        }

        $response = $this->runFeatureAction($admin, $conversation, 'player', 'change_email', $params, 'e2e-forged-' . bin2hex(random_bytes(8)), []);
        if ($response->getStatusCode() !== 403) {
            return 'a forged run got HTTP ' . $response->getStatusCode() . ' ' . $response->getContent() . ', expected 403';
        }

        return '';
    }

    /**
     * The real change. run-e2e.sh then checks every record in WordPress and
     * that no "email changed" notice went out.
     */
    private function changeEmailRun(): string
    {
        list($mailbox, , $conversation) = $this->changeMeFixtures();
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $params = ['old_email' => self::CHANGE_EMAIL, 'new_email' => self::CHANGE_EMAIL_NEW];

        $done = $this->previewAndRun($admin, $conversation, 'player', 'change_email', $params);
        $preview = $this->plain($done['preview']);
        $expected = [
            'Player #' . $this->fixtureId('change_email_player_id') . ' Casey Change: email → ' . self::CHANGE_EMAIL_NEW,
            'WordPress login of fixture-casey',
            '1 WooCommerce order: billing email → ' . self::CHANGE_EMAIL_NEW,
        ];
        foreach ($expected as $needle) {
            if (strpos($preview, $needle) === false) {
                return 'the preview does not list "' . $needle . '": ' . $this->text($done['preview']);
            }
        }

        return '';
    }

    private function rerunRegistration(): string
    {
        list($mailbox, , $conversation) = $this->fixtures(true);
        $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
        $orderId = $this->envId('SPATBRIDGE_E2E_ORDER_ID');

        $done = $this->previewAndRun($admin, $conversation, 'player', 'rerun', ['order_id' => $orderId]);
        $preview = $this->plain($done['preview']);
        foreach (['Re-run registration for order #' . $orderId, 'Last outcome: name rejected', 'Currently: not registered'] as $needle) {
            if (strpos($preview, $needle) === false) {
                return 'the preview does not say "' . $needle . '": ' . $this->text($done['preview']);
            }
        }
        $message = (string) ($done['body']['msg'] ?? '');
        if (strpos($message, 'Still not registered: name rejected') === false) {
            return 'the run says "' . $message . '", expected "Still not registered: name rejected"';
        }

        return '';
    }

    // ------------------------------------------------------------------
    // A secondary address
    // ------------------------------------------------------------------

    /**
     * run-e2e.sh has put a waitlist entry (Sam Smith, W2027 player) and a
     * player (Sam Secondary) in WordPress under an address that is not the
     * fixture customer's main one. Once the FreeScout customer carries that
     * address too, both show on the ticket, and Change email on the player
     * passes the guard with that address. The address is removed from the
     * customer again whatever happens.
     */
    private function secondaryAddress(): string
    {
        $secondary = strtolower($this->env('SPATBRIDGE_E2E_SECONDARY_EMAIL'));
        $playerId = $this->envId('SPATBRIDGE_E2E_SECONDARY_PLAYER_ID');
        list($mailbox, $customer, $conversation) = $this->fixtures(true);

        try {
            if (!\App\Email::where('email', $secondary)->exists()) {
                $customer->addEmail($secondary);
            }
            $customer = \App\Customer::find($customer->id);
            $emails = CustomerEmails::of($customer, $conversation);
            if ($emails !== [self::WP_CUSTOMER_EMAIL, $secondary]) {
                return 'the customer is looked up by ' . json_encode($emails) . ', expected the main and the secondary address';
            }

            // The contract: WordPress echoes the list and finds both records.
            $result = app(SpatClient::class)->customer($emails);
            if (!$result->ok()) {
                return 'customer() failed: ' . $result->errorCode() . ' ' . ConnectionMessage::describe($result);
            }
            $data = $result->data();
            if (($data['emails'] ?? null) !== $emails) {
                return 'WordPress echoed emails ' . json_encode($data['emails'] ?? null) . ', expected ' . json_encode($emails);
            }
            if (!in_array($playerId, array_map('intval', (array) ($data['player_ids'] ?? [])), true)) {
                return 'player #' . $playerId . ' is not in player_ids ' . json_encode($data['player_ids'] ?? null);
            }
            $found = null;
            foreach ((array) ($data['sections']['player']['players'] ?? []) as $player) {
                if ((int) ($player['id'] ?? 0) === $playerId) {
                    $found = $player;
                }
            }
            if (($found['email'] ?? null) !== $secondary) {
                return 'player #' . $playerId . ' was found under ' . json_encode($found['email'] ?? null) . ', expected ' . $secondary;
            }
            $entry = null;
            foreach ((array) ($data['sections']['waitlist']['entries'] ?? []) as $candidate) {
                if (($candidate['season'] ?? '') === 'W2027') {
                    $entry = $candidate;
                }
            }
            if (($entry['email'] ?? null) !== $secondary || ($entry['name'] ?? null) !== 'Sam Smith') {
                return 'the W2027 waitlist entry is ' . json_encode($entry) . ', expected Sam Smith under ' . $secondary;
            }

            // The sidebar: both records, the registrant line, and Change
            // email with the address the player was found under.
            $html = $this->fixtureSidebar();
            $waitlist = $this->row($html, 'W2027 · Player');
            if ($waitlist === null || strpos($waitlist['text'], 'Sam Smith · ' . $secondary) === false) {
                return 'no W2027 waitlist row naming "Sam Smith · ' . $secondary . '": ' . ($waitlist['text'] ?? $this->text($html));
            }
            $player = $this->row($html, '#23 Sam Secondary');
            if ($player === null) {
                return 'no "#23 Sam Secondary" player row: ' . $this->text($html);
            }
            $params = rawurlencode((string) json_encode(['old_email' => $secondary]));
            if (strpos($player['html'], 'params=' . $params) === false) {
                return 'the Sam Secondary row has no Change email for ' . $secondary . ': ' . $player['text'];
            }

            // The guard accepts the secondary address: the form, not a refusal.
            $admin = $this->agent(self::ADMIN_EMAIL, $mailbox);
            $form = $this->previewAction($admin, $conversation, 'player', 'change_email', ['old_email' => $secondary]);
            if (strpos($form, 'spatbridge-email-form') === false || strpos($this->plain($form), $secondary) === false) {
                return 'Change email for ' . $secondary . ' did not open the form: ' . $this->text($form);
            }
        } finally {
            \App\Email::where('email', $secondary)->delete();
            $this->forgetCachedCustomer();
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Events from WordPress
    // ------------------------------------------------------------------

    /**
     * The unmatched e-Transfer run-e2e.sh fired through the real hook, sent
     * by WP-Cron to the receiver.
     */
    private function eventUnmatchedNoted(): string
    {
        list(, , $conversation) = $this->fixtures(false);
        $eventId = $this->env('SPATBRIDGE_E2E_EVENT_ID');
        $reference = $this->env('SPATBRIDGE_E2E_EVENT_REF');

        $notes = $this->notesMentioning($conversation, $reference);
        if (count($notes) !== 1) {
            return 'expected one note for reference ' . $reference . ', found ' . count($notes);
        }
        if (strpos($notes[0], 'Unmatched e-Transfer') === false) {
            return 'the note does not say "Unmatched e-Transfer": ' . $this->text($notes[0]);
        }

        return $this->eventLogged($eventId, 'noted_tag_skipped', (int) $conversation->id);
    }

    /**
     * The same body again, as a WordPress retry after a lost answer would
     * send it (freshly signed, as WordPress signs every attempt).
     */
    private function eventDuplicateIgnored(): string
    {
        list(, , $conversation) = $this->fixtures(false);
        $eventId = $this->env('SPATBRIDGE_E2E_EVENT_ID');
        $reference = $this->env('SPATBRIDGE_E2E_EVENT_REF');

        $row = \DB::table('spatbridge_events')->where('event_id', $eventId)->first();
        if (!$row) {
            return 'event ' . $eventId . ' is not in the event log';
        }
        $body = (string) $row->payload;
        $timestamp = (string) time();
        $secret = (string) \Option::get(self::SECRET_OPTION, '');

        $response = (new \GuzzleHttp\Client(['timeout' => 10, 'http_errors' => false]))->post(self::EVENTS_URL, [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                Signer::HEADER_TIMESTAMP => $timestamp,
                Signer::HEADER_SIGNATURE => Signer::sign($timestamp, $body, $secret),
            ],
        ]);
        $answer = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || ($answer['status'] ?? null) !== 'duplicate') {
            return 'the receiver answered HTTP ' . $response->getStatusCode() . ' ' . $response->getBody() . ', expected status duplicate';
        }
        if (count($this->notesMentioning($conversation, $reference)) !== 1) {
            return 'the duplicate added a second note';
        }
        if (\DB::table('spatbridge_events')->where('event_id', $eventId)->count() !== 1) {
            return 'the duplicate added a second event log row';
        }

        return '';
    }

    /**
     * Turn on new tickets for registration.incomplete and remember what is
     * there already. Re-runs against a kept FreeScout database close the
     * earlier ticket and forget the earlier event, which would otherwise be
     * noted instead (open ticket) or skipped as a repeat.
     */
    private function eventCreateSetup(): string
    {
        list($mailbox) = $this->fixtures(true);
        $options = app(OptionStoreInterface::class);
        $options->set('spatbridge.events.registration.incomplete.create', true);
        $options->set('spatbridge.events.mailbox_id', (int) $mailbox->id);

        $email = \App\Email::where('email', self::NEW_PERSON_EMAIL)->first();
        if ($email) {
            \App\Conversation::where('customer_id', $email->customer_id)
                ->update(['status' => \App\Conversation::STATUS_CLOSED]);
        }
        \DB::table('spatbridge_events')->where('email', self::NEW_PERSON_EMAIL)->delete();

        \Cache::forever(self::CACHE_PREFIX . 'max_conversation_id', (int) \App\Conversation::max('id'));
        \Cache::forever(self::CACHE_PREFIX . 'max_job_id', (int) \DB::table('jobs')->max('id'));

        return '';
    }

    private function eventCreated(): string
    {
        $eventId = $this->env('SPATBRIDGE_E2E_EVENT_ID');
        $orderId = $this->envId('SPATBRIDGE_E2E_ORDER_ID');
        $subject = 'Registration not completed: order #' . $orderId;

        $email = \App\Email::where('email', self::NEW_PERSON_EMAIL)->first();
        if (!$email) {
            return 'no FreeScout customer for ' . self::NEW_PERSON_EMAIL;
        }
        $created = \App\Conversation::where('customer_id', $email->customer_id)
            ->where('id', '>', (int) \Cache::get(self::CACHE_PREFIX . 'max_conversation_id', 0))
            ->get();
        if ($created->count() !== 1 || $created->first()->subject !== $subject) {
            return 'expected one new conversation "' . $subject . '", found: ' . json_encode($created->pluck('subject')->all());
        }

        $conversation = $created->first();
        $logged = $this->eventLogged($eventId, 'created_tag_skipped', (int) $conversation->id);
        if ($logged !== '') {
            return $logged;
        }

        // No customer email. FreeScout only emails a customer for a message
        // thread written by an agent: the ticket must hold the note and no
        // such thread.
        $threads = \App\Thread::where('conversation_id', $conversation->id)->get();
        if ($threads->where('type', \App\Thread::TYPE_NOTE)->count() < 1) {
            return 'the new ticket has no note thread: ' . json_encode($threads->pluck('type')->all());
        }
        $messages = $threads->filter(function ($thread) {
            return (int) $thread->type === \App\Thread::TYPE_MESSAGE && $thread->created_by_user_id !== null;
        });
        if ($messages->count() !== 0) {
            return 'the new ticket has ' . $messages->count() . ' agent message thread(s), which FreeScout would email';
        }

        // And nothing queued that could email the customer. No queue worker
        // runs in e2e, so everything queued since the setup step is still in
        // jobs. The control proves both queries see a matching row first.
        $control = $this->jobsQueryControl();
        if ($control !== '') {
            return $control;
        }
        $queued = $this->queuedSince((int) \Cache::get(self::CACHE_PREFIX . 'max_job_id', 0), self::NEW_PERSON_EMAIL);
        if ($queued['emails'] !== []) {
            return 'new job(s) on the emails queue: ' . implode(', ', $queued['emails']);
        }
        if ($queued['unexpected'] !== []) {
            return 'new job(s) naming ' . self::NEW_PERSON_EMAIL . ': ' . implode(', ', $queued['unexpected']);
        }

        // Secondary: nothing was sent either.
        $sent = \DB::table('send_logs')->where('email', self::NEW_PERSON_EMAIL)->count();
        if ($sent !== 0) {
            return $sent . ' send_logs rows for ' . self::NEW_PERSON_EMAIL;
        }

        return '';
    }

    /**
     * Jobs queued after job id $since, as "#id Class[ action]" labels:
     * every job on FreeScout's `emails` queue (where all customer mail goes,
     * e.g. SendReplyToCustomer, whose payload holds only model ids), and every
     * other job whose payload contains $address, except the one FreeScout
     * queues for any ticket a user opens: TriggerAction for the module hook
     * `conversation.created_by_user`, which carries the conversation (so its
     * customer_email) and sends nothing in core.
     *
     * SendNotificationToUsers also goes on `emails`, but it mails agents
     * subscribed to the mailbox (e.g. about the new note), never the
     * customer, so it isn't counted as customer mail. It is still reported if
     * its payload names $address.
     *
     * @return array{emails: string[], unexpected: string[]}
     */
    private function queuedSince(int $since, string $address): array
    {
        $found = ['emails' => [], 'unexpected' => []];
        foreach (\DB::table('jobs')->where('id', '>', $since)->orderBy('id')->get(['id', 'queue', 'payload']) as $job) {
            $payload = (string) $job->payload;
            $decoded = json_decode($payload, true);
            $class = is_array($decoded) && isset($decoded['displayName']) ? (string) $decoded['displayName'] : '?';
            $command = is_array($decoded) && isset($decoded['data']['command']) ? (string) $decoded['data']['command'] : '';
            $action = preg_match('/s:6:"action";s:\d+:"([^"]*)"/', $command, $m) ? $m[1] : '';
            $label = '#' . $job->id . ' ' . $class . ($action === '' ? '' : ' ' . $action);

            if ($job->queue === 'emails' && $class !== 'App\\Jobs\\SendNotificationToUsers') {
                $found['emails'][] = $label;
            } elseif (
                strpos($payload, $address) !== false
                && !($class === 'App\\Jobs\\TriggerAction' && $action === 'conversation.created_by_user')
            ) {
                $found['unexpected'][] = $label;
            }
        }

        return $found;
    }

    /**
     * Positive control for queuedSince(): inside a transaction that is rolled
     * back, a job on the emails queue, a job naming a marker address, and an
     * agent notification on the emails queue naming it must all be reported,
     * so a renamed table, column or queue, or the agent-notification
     * exception, can't make the real check pass silently.
     */
    private function jobsQueryControl(): string
    {
        if (!\Schema::hasTable('jobs') || !\Schema::hasColumn('jobs', 'payload') || !\Schema::hasColumn('jobs', 'queue')) {
            return 'FreeScout has no jobs.queue / jobs.payload; the queued-email check cannot run';
        }
        $marker = 'spatbridge-e2e-control-' . bin2hex(random_bytes(6)) . '@example.test';
        $since = (int) \DB::table('jobs')->max('id');
        $row = function (string $queue, string $class = 'SpatBridgeE2eControl') use ($marker): array {
            return [
                'queue' => $queue,
                'payload' => json_encode(['displayName' => $class, 'data' => ['command' => 'to ' . $marker]]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time() + 86400,
                'created_at' => time(),
            ];
        };
        \DB::beginTransaction();
        try {
            \DB::table('jobs')->insert([
                $row('emails'),
                $row('default'),
                $row('emails', 'App\\Jobs\\SendNotificationToUsers'),
            ]);
            $found = $this->queuedSince($since, $marker);
        } finally {
            \DB::rollBack();
        }
        if (count($found['emails']) !== 1 || count($found['unexpected']) !== 2) {
            return 'the jobs control saw ' . json_encode($found)
                . ', expected one emails job and two jobs naming the marker (one an agent notification)';
        }

        return '';
    }

    /**
     * The event that waited in WordPress's outbox while freescout-web was
     * down, delivered on the retry.
     */
    private function eventRetried(): string
    {
        list(, , $conversation) = $this->fixtures(false);
        $reference = $this->env('SPATBRIDGE_E2E_EVENT_REF');

        $count = count($this->notesMentioning($conversation, $reference));
        if ($count !== 1) {
            return 'expected one note for reference ' . $reference . ' after the retry, found ' . $count;
        }

        return $this->eventLogged($this->env('SPATBRIDGE_E2E_EVENT_ID'), 'noted_tag_skipped', (int) $conversation->id);
    }

    /**
     * '' when the event log has exactly one row for the event, with this
     * outcome and naming this conversation.
     */
    private function eventLogged(string $eventId, string $outcome, int $conversationId): string
    {
        $rows = \DB::table('spatbridge_events')->where('event_id', $eventId)->get();
        if ($rows->count() !== 1) {
            return 'expected one event log row for ' . $eventId . ', found ' . $rows->count();
        }
        $row = $rows->first();
        if ($row->outcome !== $outcome) {
            return 'event ' . $eventId . ' has outcome "' . $row->outcome . '" (' . $row->detail . '), expected ' . $outcome;
        }
        if (!in_array($conversationId, array_map('intval', preg_split('/\D+/', (string) $row->conversation_ids, -1, PREG_SPLIT_NO_EMPTY)), true)) {
            return 'event ' . $eventId . ' names conversations "' . $row->conversation_ids . '", not #' . $conversationId;
        }

        return '';
    }

    /**
     * Bodies of the conversation's notes that contain $needle.
     *
     * @return string[]
     */
    private function notesMentioning($conversation, string $needle): array
    {
        return \App\Thread::where('conversation_id', $conversation->id)
            ->where('type', \App\Thread::TYPE_NOTE)
            ->where('body', 'like', '%' . $needle . '%')
            ->pluck('body')
            ->all();
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
        \App\Email::where('customer_id', $customer->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(self::CUSTOMER_EMAIL)])
            ->update(['email' => self::CUSTOMER_EMAIL]);
        $customer = \App\Customer::find($customer->id);

        return [$mailbox, $customer, $this->conversationFor($mailbox, $customer, self::CUSTOMER_EMAIL, 'Which team am I on?')];
    }

    /**
     * The change-me customer's ticket, for the change-email checks. Created
     * on first use; needs the main fixtures' mailbox.
     *
     * @return array{0: \App\Mailbox, 1: \App\Customer, 2: \App\Conversation}
     */
    private function changeMeFixtures(): array
    {
        list($mailbox) = $this->fixtures(true);
        $email = \App\Email::where('email', self::CHANGE_EMAIL)->first();
        $customer = $email
            ? \App\Customer::find($email->customer_id)
            : \App\Customer::create(self::CHANGE_EMAIL, ['first_name' => 'Casey', 'last_name' => 'Change']);

        return [$mailbox, $customer, $this->conversationFor($mailbox, $customer, self::CHANGE_EMAIL, 'Please update my email')];
    }

    /**
     * The customer's open email conversation in the e2e mailbox, created when
     * missing.
     */
    private function conversationFor($mailbox, $customer, string $email, string $subject): \App\Conversation
    {
        $conversation = \App\Conversation::where('mailbox_id', $mailbox->id)
            ->where('customer_id', $customer->id)
            ->first();
        if (!$conversation) {
            $conversation = new \App\Conversation();
            $conversation->type = \App\Conversation::TYPE_EMAIL;
            $conversation->subject = $subject;
            $conversation->preview = '';
            $conversation->mailbox_id = $mailbox->id;
            $conversation->customer_id = $customer->id;
            $conversation->customer_email = $email;
            $conversation->status = \App\Conversation::STATUS_ACTIVE;
            $conversation->state = \App\Conversation::STATE_PUBLISHED;
            $conversation->source_via = \App\Conversation::PERSON_CUSTOMER;
            $conversation->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
            $conversation->created_by_customer_id = $customer->id;
            $conversation->number = (int) \App\Conversation::max('number') + 1;
            $conversation->updateFolder($mailbox);
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * The fixture customer's sidebar as the admin sees it, fresh from
     * WordPress.
     */
    private function fixtureSidebar(): string
    {
        list($mailbox, $customer, $conversation) = $this->fixtures(true);
        $this->forgetCachedCustomer();
        \Auth::setUser($this->agent(self::ADMIN_EMAIL, $mailbox));

        return $this->renderSidebar($customer, $conversation, $mailbox);
    }

    /**
     * The first sidebar row whose title is $title: its badge, its plain
     * text (title, badge, detail lines, action labels) and its markup. Null
     * when there is no such row.
     *
     * @return array{badge: string, text: string, html: string}|null
     */
    private function row(string $html, string $title): ?array
    {
        preg_match_all('#<li>(.*?)</li>#s', $html, $matches);
        foreach ($matches[1] as $block) {
            $text = $this->plain($block);
            if (strpos($text, $title) !== 0) {
                continue;
            }
            $badge = preg_match('#<span class="pull-right[^"]*">(.*?)</span>#s', $block, $m) ? $this->plain($m[1]) : '';

            return ['badge' => $badge, 'text' => $text, 'html' => $block];
        }

        return null;
    }

    /**
     * Preview, then run with the preview's key, as the pop-up does, and
     * expect success and exactly one new action note.
     *
     * @return array{preview: string, body: array}
     * @throws \UnexpectedValueException with the failure detail
     */
    private function previewAndRun(\App\User $user, $conversation, string $feature, string $action, array $params): array
    {
        $label = $feature . '.' . $action;
        $notesBefore = $this->noteCount($conversation);

        $html = $this->previewAction($user, $conversation, $feature, $action, $params);
        $key = $this->idempotencyKey($html);
        if ($key === '') {
            throw new \UnexpectedValueException('no ' . $label . ' preview; rendered text: ' . $this->text($html));
        }

        $response = $this->runFeatureAction($user, $conversation, $feature, $action, $params, $key, []);
        $body = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() !== 200 || ($body['status'] ?? null) !== 'success') {
            throw new \UnexpectedValueException($label . ' failed: HTTP ' . $response->getStatusCode() . ' ' . $response->getContent());
        }
        $added = $this->noteCount($conversation) - $notesBefore;
        if ($added !== 1) {
            throw new \UnexpectedValueException($label . ' added ' . $added . ' notes, expected 1');
        }

        return ['preview' => $html, 'body' => $body];
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
        return $this->previewAction($user, $conversation, 'etransfers', 'match', $params);
    }

    private function previewAction(\App\User $user, $conversation, string $feature, string $action, array $params): string
    {
        $request = $this->request($user, 'GET', [
            'feature' => $feature,
            'action' => $action,
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
        return $this->runFeatureAction($user, $conversation, 'etransfers', 'match', $params, $key, $confirm);
    }

    /**
     * @param string[] $confirm
     */
    private function runFeatureAction(
        \App\User $user,
        $conversation,
        string $feature,
        string $action,
        array $params,
        string $key,
        array $confirm
    ): \Illuminate\Http\JsonResponse {
        $request = $this->request($user, 'POST', [
            'feature' => $feature,
            'action' => $action,
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

    /**
     * Action notes on the conversation; notes from WordPress events are not
     * counted.
     */
    private function noteCount($conversation): int
    {
        return \App\Thread::where('conversation_id', $conversation->id)
            ->where('type', \App\Thread::TYPE_NOTE)
            ->where('body', 'like', '%' . self::NOTE_MARK . '%')
            ->where('body', 'not like', '%' . self::EVENT_NOTE_MARK . '%')
            ->count();
    }

    private function env(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') {
            throw new \RuntimeException($name . ' is not set');
        }

        return $value;
    }

    private function envId(string $name): int
    {
        $value = $this->env($name);
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new \RuntimeException($name . ' is not an id: ' . $value);
        }

        return (int) $value;
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

    /**
     * Drops the sidebar's cached lookup for the fixture customer, under the
     * key the sidebar uses for the customer's current addresses.
     */
    private function forgetCachedCustomer(): void
    {
        list(, $customer, $conversation) = $this->fixtures(false);
        if ($customer !== null) {
            \Cache::forget(CustomerEmails::cacheKey(CustomerEmails::of($customer, $conversation)));
        }
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
