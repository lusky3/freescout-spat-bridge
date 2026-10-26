#!/usr/bin/env bash
# ==========================================================================
# run-e2e.sh -- bring up WordPress (sportspress-sandbox + the SPAT plugins)
# and FreeScout (with this module), then assert headlessly, through
# `php artisan tinker`, that the sidebar Player card, the e-Transfers and
# Waitlist panels, the action framework (preview, run, permissions, notes),
# the Player card actions and the events WordPress sends work end to end.
# WordPress-side results (captured mail, changed emails, the event outbox) are
# checked with `wp` in the WordPress container between the tinker phases.
#
# Modeled on freescout-sportspress-e2e's run-e2e.sh. Safe to re-run:
# FreeScout core is cloned only if missing, migrations are idempotent, the
# users are created only once, and the WordPress fixtures are guarded. The
# action checks change WordPress (they complete the on-hold order), so the
# WordPress container is recreated on every run; its database lives inside it.
#
# Every artisan and tinker call runs as the PHP-FPM user (www-data, from the
# php:fpm base image of ../docker/php/Dockerfile), never as root. Cache
# directories created by root are unwritable for the web server, and the
# sidebar then silently renders nothing. The last check asserts this.
#
# Sibling checkouts (override with environment variables):
#   SANDBOX_PATH  default ../../sportspress-sandbox        (relative to e2e/)
#   SPAT_PATH     default ../../SportsPress-Admin-Tools    (relative to e2e/)
# Either may be a plain directory, such as a `git archive` snapshot of a commit.
#
# Prints one PASS/FAIL line per check and exits non-zero if any check fails.
# Tear down with: docker compose -p spatbridge-e2e down -v
# ==========================================================================
# The `wp eval` snippets are PHP in single quotes on purpose: their $ is PHP's.
# shellcheck disable=SC2016
set -euo pipefail

cd "$(dirname "$0")"

SANDBOX_PATH="$(cd "${SANDBOX_PATH:-../../sportspress-sandbox}" && pwd)"
SPAT_PATH="$(cd "${SPAT_PATH:-../../SportsPress-Admin-Tools}" && pwd)"
export SANDBOX_PATH SPAT_PATH

COMPOSE="docker compose -p spatbridge-e2e -f docker-compose.yml"
FREESCOUT_URL="http://localhost:8095"
SPORTSPRESS_URL="http://localhost:8094"
ADMIN_EMAIL="admin@example.test"
ADMIN_PASSWORD="AdminPass123!"
# A non-admin agent. WordPress has a subscriber with the same email, so the
# agent reaches WordPress as a known user who lacks the capability.
NOCAP_EMAIL="nocap@example.test"
NOCAP_PASSWORD="NoCapPass123!"
# The event receiver as WordPress reaches it on the compose network.
EVENTS_URL="http://freescout-web/spatbridge/events"
# The fixture customer's address as WordPress stores it.
CUSTOMER_EMAIL="fixture-bridge@example.test"

# Every check, in the order the results are printed. A check passes when
# some phase printed `PASS <name>` and none printed `FAIL <name>: ...`.
CHECKS=(
    ping_ok
    wrong_secret_names_cause
    sidebar_renders_player_card
    wp_down_shows_unavailable
    etransfers_panel
    action_preview_mismatch
    action_forbidden_actor
    action_match_runs
    action_permission_denied
    waitlist_panel
    waitlist_offer_flow
    waitlist_reoffer
    waitlist_forbidden
    change_email_flow
    change_email_foreign_old_email
    rerun_registration
    event_unmatched_notes_ticket
    event_duplicate_ignored
    event_creates_ticket_when_enabled
    event_retry_after_freescout_down
    cache_not_root_owned
)
EXPECTED_CHECKS=21

# php-fpm's pool user in the php:fpm base image.
FPM_USER="www-data"
APP_EXEC="$COMPOSE exec -T -u $FPM_USER"
FIXTURES=""
ALL_OUTPUT=""

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# Keeps a phase's output (PASS/FAIL lines and anything else) for the results.
collect() {
    ALL_OUTPUT+="$1"$'\n'
}

# Records a WordPress-side step of a check: a FAIL line when detail is set.
# With "final", the step is the check's last, so success prints PASS.
wp_step() {
    local name="$1" detail="$2" final="${3:-}"
    if [ -n "$detail" ]; then
        collect "FAIL ${name}: ${detail}"
    elif [ "$final" = "final" ]; then
        collect "PASS ${name}"
    fi
}

# Runs PHP from stdin in tinker as the FPM user. HOME points psysh's config
# and history at a directory that user can write.
tinker() {
    $APP_EXEC -e HOME=/tmp "$@" freescout-app php artisan tinker
}

# Runs e2e/assert.php in a fresh tinker process for the given phase, and
# collects its output. Further arguments are NAME=VALUE pairs passed to the
# phase as environment variables. psysh echoes piped input, but the input
# line holds no PASS/FAIL text, so only the script's own output counts.
run_assertions() {
    local phase="$1" output
    shift
    local env_args=(-e SPATBRIDGE_E2E_PHASE="$phase" -e SPATBRIDGE_E2E_FIXTURES="$FIXTURES")
    local pair
    for pair in "$@"; do
        env_args+=(-e "$pair")
    done
    output=$(tinker "${env_args[@]}" <<'PHP' 2>&1 || true
require '/opt/spatbridge-e2e/assert.php';
PHP
)
    collect "$output"
}

# Runs PHP in WordPress with `wp eval` and prints its last output line.
# Further arguments are NAME=VALUE pairs the code reads with getenv(); values
# never become part of the PHP source.
wp_eval() {
    local code="$1" pair
    shift
    local env_args=()
    for pair in "$@"; do
        env_args+=(-e "$pair")
    done
    $COMPOSE exec -T "${env_args[@]}" sportspress-test wp eval "$code" --allow-root 2>/dev/null | tail -n 1 || true
}

# Runs WordPress's due cron events, which is when SPAT sends queued events
# (the sandbox sets DISABLE_WP_CRON, so nothing else does). A pass can end
# early without an error: WooCommerce's background-process healthcheck events
# (e.g. wp_1_wc_regenerate_images_cron) call exit. So passes repeat, up to
# three, until WP-CLI reports Success.
wp_cron() {
    local pass output
    for pass in 1 2 3; do
        # Only WP-CLI's own lines and fatal errors; the sandbox prints a stack
        # trace per PHP notice.
        output=$($COMPOSE exec -T sportspress-test wp cron event run --due-now --allow-root 2>&1 \
            | grep -E '^(Executed|Success|Warning|Error)|PHP (Fatal|Parse) error' || true)
        printf 'cron pass %s:\n%s\n' "$pass" "$output"
        case "$output" in
            *"Success: "*) return 0 ;;
        esac
    done
}

# Emails WordPress captured (spb-mail-capture mu-plugin) to $1.
mail_count() {
    wp_eval '
        $n = 0;
        foreach ( (array) get_option( "spb_captured_mail", array() ) as $mail ) {
            if ( strtolower( trim( (string) ( $mail["to"] ?? "" ) ) ) === strtolower( getenv( "ADDR" ) ) ) {
                $n++;
            }
        }
        echo $n;
    ' ADDR="$1"
}

# "status attempts" of the outbox row for event $1.
outbox_row() {
    wp_eval '
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts FROM " . SPAT_FreeScout_Events::table() . " WHERE event_id = %s", getenv( "EVENT_ID" ) ) );
        echo $row ? $row->status . " " . $row->attempts : "missing";
    ' EVENT_ID="$1"
}

# Fires the real spat_payment_unmatched hook for the fixture customer with
# reference $1 and prints the id of the event it queued.
fire_unmatched() {
    wp_eval '
        global $wpdb;
        do_action( "spat_payment_unmatched", "Pat E2E", "42.50", getenv( "REF" ), array( "log_id" => 0, "from_email" => getenv( "EMAIL" ), "reason" => "No matching order found" ) );
        echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM " . SPAT_FreeScout_Events::table() . " WHERE type = %s AND payload LIKE %s ORDER BY id DESC LIMIT 1", "etransfer.unmatched", "%" . $wpdb->esc_like( getenv( "REF" ) ) . "%" ) );
    ' REF="$1" EMAIL="$CUSTOMER_EMAIL"
}

# Waits until WordPress reports healthy and the sandbox's setup program,
# which supervisor runs on every container start, has exited since $1 (a UTC
# timestamp taken before the start). The health endpoint says "ready" as
# soon as a baseline export exists, and on a restart the setup runs again in
# the background; its WP-CLI writes would race the checks (one of them wrote
# back a stale cron array and dropped a queued event's send).
wait_for_wordpress() {
    local since="$1"
    log "Waiting for sportspress-test to report healthy and finish its setup..."
    for i in $(seq 1 40); do
        if curl -sf "${SPORTSPRESS_URL}/wp-json/test/v1/health" | grep -q '"status":"ready"' \
            && $COMPOSE logs --no-log-prefix --since "$since" sportspress-test 2>/dev/null | grep 'exited: setup' >/dev/null; then
            # (grep without -q reads the whole log, so `logs` never gets
            # SIGPIPE, which pipefail would report as a failure.)
            echo "sportspress-test is ready."
            return 0
        fi
        if [ "$i" -eq 40 ]; then
            echo "sportspress-test did not become healthy in time." >&2
            exit 1
        fi
        sleep 6
    done
}

# Creates a FreeScout user unless one with that email already exists.
# Args: role first-name last-name email password
ensure_user() {
    # psysh echoes piped input, so count rows rather than grep for a sentinel
    # that also appears in the echoed source line. The trailing "\n" keeps
    # psysh from appending its return glyph to the number.
    local count
    count=$(tinker <<PHP 2>/dev/null | grep -E '^[0-9]+$' | tail -1
echo \App\User::where('email', '$4')->count() . "\n";
PHP
)
    if [ "${count:-0}" -eq 0 ]; then
        $APP_EXEC freescout-app php artisan freescout:create-user \
            --role="$1" --firstName="$2" --lastName="$3" --email="$4" --password="$5" -n
    else
        echo "User $4 already exists, skipping."
    fi
}

# --------------------------------------------------------------------
# 1. FreeScout core: clone fresh if missing, write a docker-appropriate .env
# --------------------------------------------------------------------
if [ ! -d freescout-core ] || [ ! -f freescout-core/artisan ]; then
    log "Cloning FreeScout core into ./freescout-core (gitignored)"
    rm -rf freescout-core
    git clone --depth 1 https://github.com/freescout-help-desk/freescout.git freescout-core
fi

if [ ! -f freescout-core/.env ]; then
    log "Writing freescout-core/.env"
    cp freescout-core/.env.example freescout-core/.env
    sed -i 's/^DB_HOST=.*/DB_HOST=freescout-db/' freescout-core/.env
    sed -i 's/^DB_DATABASE=.*/DB_DATABASE=freescout/' freescout-core/.env
    sed -i 's/^DB_USERNAME=.*/DB_USERNAME=freescout/' freescout-core/.env
    sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=freescout/' freescout-core/.env
    sed -i "s#^APP_URL=.*#APP_URL=${FREESCOUT_URL}#" freescout-core/.env
fi
# WordPress posts events to http://freescout-web, so FreeScout must accept
# that host as well as localhost. Also covers a .env from an older run.
grep -q '^APP_TRUSTED_HOSTS=' freescout-core/.env || echo 'APP_TRUSTED_HOSTS=localhost,freescout-web' >> freescout-core/.env

# --------------------------------------------------------------------
# 2. Build + start everything, with a fresh WordPress
# --------------------------------------------------------------------
log "docker compose up -d --build (WordPress from ${SANDBOX_PATH}, SPAT from ${SPAT_PATH})"
WP_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
$COMPOSE up -d --build --force-recreate sportspress-test
$COMPOSE up -d --build

# --------------------------------------------------------------------
# 3. Wait for sportspress-test's own healthcheck and setup
# --------------------------------------------------------------------
wait_for_wordpress "$WP_STARTED"

# --------------------------------------------------------------------
# 4. FreeScout install, entirely via artisan -- no browser wizard
# --------------------------------------------------------------------
log "Generating FreeScout APP_KEY (if not already set)"
if grep -q '^APP_KEY=base64:' freescout-core/.env; then
    echo "APP_KEY already set, skipping."
else
    # .env belongs to the host user, so the key is written from here.
    APP_KEY=$($APP_EXEC freescout-app php artisan key:generate --show | tr -d '\r' | grep -E '^base64:' | tail -1)
    if [ -z "$APP_KEY" ]; then
        echo "key:generate --show printed no key." >&2
        exit 1
    fi
    sed -i "s#^APP_KEY=.*#APP_KEY=${APP_KEY}#" freescout-core/.env
fi

log "Running FreeScout migrations"
$APP_EXEC freescout-app php artisan migrate --force

log "Creating the FreeScout users (each skipped if it already exists)"
ensure_user admin Admin User "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
ensure_user user No Cap "$NOCAP_EMAIL" "$NOCAP_PASSWORD"

log "Marking FreeScout as installed (storage/.installed) and clearing cache"
$APP_EXEC freescout-app touch storage/.installed
$APP_EXEC freescout-app php artisan freescout:clear-cache

# --------------------------------------------------------------------
# 5. Enable the module
# --------------------------------------------------------------------
log "Enabling the SpatBridge module"
# FreeScout's source of truth for activation is the `modules` table
# (App\Module::setActive). freescout:module-install then runs the module's
# migrations and symlinks public/modules/spatbridge to the module's Public/
# directory, which is where the `javascripts` filter points sidebar.js.
tinker <<'PHP' >/dev/null
\App\Module::setActive('spatbridge', true);
PHP
$APP_EXEC freescout-app php artisan freescout:module-install spatbridge
if ! $APP_EXEC freescout-app test -f public/modules/spatbridge/js/sidebar.js; then
    echo "public/modules/spatbridge/js/sidebar.js is missing after module install." >&2
    exit 1
fi

# --------------------------------------------------------------------
# 6. Shared secret, WordPress fixtures, module options
# --------------------------------------------------------------------
log "Generating a shared secret"
SECRET=$(openssl rand -hex 32)

log "Creating the WordPress fixtures (also stores the secret and the events URL on the WordPress side)"
# The script prints one JSON line with the fixture ids; assert.php reads it
# from SPATBRIDGE_E2E_FIXTURES. With the events URL, SPAT queues events for
# FreeScout; WP-Cron is off in the sandbox, so they wait until wp_cron runs.
FIXTURES=$($COMPOSE exec -T sportspress-test bash /usr/local/bin/fixtures-freescout-bridge.sh "$SECRET" "$EVENTS_URL" | tail -n 1)
case "$FIXTURES" in
    '{'*) echo "$FIXTURES" ;;
    *)
        echo "The fixture script printed no JSON: $FIXTURES" >&2
        exit 1
        ;;
esac

log "Configuring the module options"
tinker <<PHP >/dev/null
\Option::set('spatbridge.wp_base_url', 'http://sportspress-test');
\Option::set('spatbridge.shared_secret', '$SECRET');
PHP
$APP_EXEC freescout-app php artisan freescout:clear-cache

# --------------------------------------------------------------------
# 7. Assertions: WordPress up, WordPress down, WordPress back for the
#    e-Transfers panel and the actions
# --------------------------------------------------------------------
log "Running assertions with WordPress up"
run_assertions main

log "Stopping sportspress-test, then running the WordPress-down assertion"
$COMPOSE stop sportspress-test
run_assertions wp_down

log "Starting sportspress-test again for the e-Transfers and action assertions"
WP_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
$COMPOSE start sportspress-test
wait_for_wordpress "$WP_STARTED"
run_assertions actions

# --------------------------------------------------------------------
# 8. Waitlist: panel, offer flow, re-offer, forbidden actor. WordPress
#    captures every email (spb-mail-capture), so the offers are counted there.
# --------------------------------------------------------------------
log "Waitlist assertions"
run_assertions waitlist_panel

MAIL_BEFORE=$(mail_count "$CUSTOMER_EMAIL")
run_assertions waitlist_offer
MAIL_AFTER=$(mail_count "$CUSTOMER_EMAIL")
if [ "$MAIL_AFTER" != "$((MAIL_BEFORE + 1))" ]; then
    wp_step waitlist_offer_flow "WordPress captured ${MAIL_BEFORE} then ${MAIL_AFTER} emails to ${CUSTOMER_EMAIL}, expected one more"
fi
run_assertions waitlist_offer_after

MAIL_BEFORE=$(mail_count "$CUSTOMER_EMAIL")
run_assertions waitlist_reoffer
MAIL_AFTER=$(mail_count "$CUSTOMER_EMAIL")
if [ "$MAIL_AFTER" != "$((MAIL_BEFORE + 2))" ]; then
    wp_step waitlist_reoffer "WordPress captured ${MAIL_BEFORE} then ${MAIL_AFTER} emails to ${CUSTOMER_EMAIL}, expected two more"
fi

# --------------------------------------------------------------------
# 9. Player card actions: change email (refusals first, then the change),
#    re-run registration
# --------------------------------------------------------------------
log "Change-email assertions"
# "player spt_email|login email|order billing email" for the change-me fixture.
casey_state() {
    wp_eval '
        $player = (int) getenv( "PLAYER_ID" );
        $user   = get_user_by( "login", "fixture-casey" );
        $orders = wc_get_orders( array( "limit" => 1, "meta_key" => "_spb_fixture", "meta_value" => "change-email" ) );
        echo implode( "|", array(
            get_post_meta( $player, "spt_email", true ),
            $user ? $user->user_email : "no-user",
            $orders ? $orders[0]->get_billing_email() : "no-order",
        ) );
    ' PLAYER_ID="$(printf '%s' "$FIXTURES" | sed -n 's/.*"change_email_player_id":\([0-9]*\).*/\1/p')"
}
# "Jordan's spt_email|fixture-bridge login email|logins on the foreign address".
jordan_state() {
    wp_eval '
        $ids  = get_posts( array( "post_type" => "sp_player", "title" => "Jordan Smith", "post_status" => "any", "fields" => "ids", "posts_per_page" => 1 ) );
        $user = get_user_by( "login", "fixture-bridge" );
        echo implode( "|", array(
            $ids ? get_post_meta( $ids[0], "spt_email", true ) : "no-player",
            $user ? $user->user_email : "no-user",
            get_user_by( "email", "foreign-new@example.test" ) ? "taken" : "free",
        ) );
    '
}
# Captured emails that look like WordPress's "Email Changed" notice, or that
# went to either of Casey's addresses.
email_change_mail() {
    wp_eval '
        $n = 0;
        foreach ( (array) get_option( "spb_captured_mail", array() ) as $mail ) {
            $to = strtolower( (string) ( $mail["to"] ?? "" ) );
            if ( false !== stripos( (string) ( $mail["subject"] ?? "" ), "email changed" ) || false !== strpos( $to, "change-me@" ) || false !== strpos( $to, "casey.new@" ) ) {
                $n++;
            }
        }
        echo $n;
    '
}

CASEY_EXPECTED="change-me@example.test|change-me@example.test|change-me@example.test"
JORDAN_EXPECTED="${CUSTOMER_EMAIL}|${CUSTOMER_EMAIL}|free"
run_assertions change_email_refusals
STATE=$(casey_state)
if [ "$STATE" != "$CASEY_EXPECTED" ]; then
    wp_step change_email_flow "the refused change to ${CUSTOMER_EMAIL} changed WordPress: ${STATE}"
fi
STATE=$(jordan_state)
if [ "$STATE" != "$JORDAN_EXPECTED" ]; then
    wp_step change_email_foreign_old_email "the refused change of another customer's address changed WordPress: ${STATE}"
fi

NOTICES_BEFORE=$(email_change_mail)
run_assertions change_email_run
STATE=$(casey_state)
NOTICES_AFTER=$(email_change_mail)
if [ "$STATE" != "casey.new@example.test|casey.new@example.test|casey.new@example.test" ]; then
    wp_step change_email_flow "after the change, WordPress has player|login|order = ${STATE}"
elif [ "$NOTICES_AFTER" != "$NOTICES_BEFORE" ]; then
    wp_step change_email_flow "WordPress sent an email-changed notice (${NOTICES_BEFORE} then ${NOTICES_AFTER} matching captured emails)"
else
    wp_step change_email_flow "" final
fi

log "Re-run registration assertion"
# The attention order logged name_rejected, but its billing name is valid,
# so a real re-run would register it. An invalid name makes WordPress reject
# it again, which is the case the check is about.
ATTENTION_ORDER=$(wp_eval '
    $orders = wc_get_orders( array( "limit" => 1, "meta_key" => "_spb_fixture", "meta_value" => "attention" ) );
    if ( $orders ) {
        $orders[0]->set_billing_last_name( "Sm1th" );
        $orders[0]->save();
        echo $orders[0]->get_id();
    }
')
run_assertions rerun SPATBRIDGE_E2E_ORDER_ID="$ATTENTION_ORDER"

# --------------------------------------------------------------------
# 10. Events from WordPress: the real hooks queue them in SPAT's outbox and
#     `wp cron event run` sends them to http://freescout-web/spatbridge/events
# --------------------------------------------------------------------
log "Sending the events earlier checks queued (match, offers, re-run)"
wp_cron

log "Event: unmatched e-Transfer noted on the open ticket, then a duplicate ignored"
EVENT_REF="E2E-UNMATCHED-$(openssl rand -hex 4)"
EVENT_ID=$(fire_unmatched "$EVENT_REF")
wp_cron
ROW=$(outbox_row "$EVENT_ID")
if [ "$ROW" != "sent 1" ]; then
    wp_step event_unmatched_notes_ticket "outbox row for event '${EVENT_ID}' is '${ROW}', expected 'sent 1'"
fi
run_assertions events_received SPATBRIDGE_E2E_EVENT_ID="$EVENT_ID" SPATBRIDGE_E2E_EVENT_REF="$EVENT_REF"

log "Event: registration.incomplete opens a ticket for a new customer"
run_assertions events_create_setup
CREATED=$(wp_eval '
    global $wpdb;
    $order = wc_create_order();
    $order->set_billing_email( "new-person@example.test" );
    $order->set_billing_first_name( "New" );
    $order->set_billing_last_name( "Person" );
    $order->save();
    do_action( "spr_registration_outcome", $order->get_id(), "name_rejected", array() );
    echo $order->get_id() . " " . $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM " . SPAT_FreeScout_Events::table() . " WHERE type = %s AND email = %s ORDER BY id DESC LIMIT 1", "registration.incomplete", "new-person@example.test" ) );
')
NEW_ORDER="${CREATED%% *}"
EVENT_ID="${CREATED#* }"
wp_cron
run_assertions events_created SPATBRIDGE_E2E_EVENT_ID="$EVENT_ID" SPATBRIDGE_E2E_ORDER_ID="$NEW_ORDER"

log "Event: queued while freescout-web is down, delivered on the retry"
$COMPOSE stop freescout-web
EVENT_REF="E2E-RETRY-$(openssl rand -hex 4)"
EVENT_ID=$(fire_unmatched "$EVENT_REF")
wp_cron
ROW=$(outbox_row "$EVENT_ID")
if [ "$ROW" != "pending 1" ]; then
    wp_step event_retry_after_freescout_down "with freescout-web down, outbox row '${EVENT_ID}' is '${ROW}', expected 'pending 1'"
fi
$COMPOSE start freescout-web
for _ in $(seq 1 20); do
    curl -s -o /dev/null "${FREESCOUT_URL}/login" && break
    sleep 1
done
# The retry is due in 60 s. Make the row and its cron event due now.
wp_eval '
    global $wpdb;
    $id = getenv( "EVENT_ID" );
    $wpdb->update( SPAT_FreeScout_Events::table(), array( "next_attempt_at" => gmdate( "Y-m-d H:i:s", time() - 60 ) ), array( "event_id" => $id ) );
    wp_clear_scheduled_hook( SPAT_FreeScout_Events::SEND_HOOK, array( $id ) );
    wp_schedule_single_event( time() - 60, SPAT_FreeScout_Events::SEND_HOOK, array( $id ) );
    echo "due";
' EVENT_ID="$EVENT_ID" >/dev/null
wp_cron
ROW=$(outbox_row "$EVENT_ID")
if [ "$ROW" != "sent 2" ]; then
    wp_step event_retry_after_freescout_down "after freescout-web came back, outbox row '${EVENT_ID}' is '${ROW}', expected 'sent 2'"
fi
run_assertions events_retried SPATBRIDGE_E2E_EVENT_ID="$EVENT_ID" SPATBRIDGE_E2E_EVENT_REF="$EVENT_REF"

# Read as root so nothing is hidden by permissions; nothing is written.
log "Checking storage/framework/cache for root-owned entries"
ROOT_OWNED=$($COMPOSE exec -T freescout-app find storage/framework/cache -user root 2>&1 || true)
if [ -z "$ROOT_OWNED" ]; then
    collect "PASS cache_not_root_owned"
else
    collect "FAIL cache_not_root_owned: $(printf '%s' "$ROOT_OWNED" | head -5 | tr '\n' ' ')"
fi

# One line per check, in CHECKS order: its first FAIL line, else PASS when a
# phase printed PASS for it, else FAIL for a missing result.
if [ "${#CHECKS[@]}" -ne "$EXPECTED_CHECKS" ]; then
    echo "CHECKS lists ${#CHECKS[@]} checks, EXPECTED_CHECKS is ${EXPECTED_CHECKS}." >&2
    exit 1
fi
RESULTS=""
for name in "${CHECKS[@]}"; do
    lines=$(printf '%s\n' "$ALL_OUTPUT" | grep -E "^(PASS|FAIL) ${name}(:|\$)" || true)
    failure=$(printf '%s\n' "$lines" | grep -m 1 '^FAIL ' || true)
    if [ -n "$failure" ]; then
        RESULTS+="${failure}"$'\n'
    elif printf '%s\n' "$lines" | grep -q '^PASS '; then
        RESULTS+="PASS ${name}"$'\n'
    else
        RESULTS+="FAIL ${name}: no result"$'\n'
    fi
done

log "Results"
printf '%s' "$RESULTS"

PASSES=$(printf '%s' "$RESULTS" | grep -c '^PASS ' || true)
if printf '%s' "$RESULTS" | grep -q '^FAIL ' || [ "$PASSES" -ne "$EXPECTED_CHECKS" ]; then
    echo >&2
    echo "End-to-end checks failed (${PASSES}/${EXPECTED_CHECKS} passed). Raw tinker output:" >&2
    printf '%s\n' "$ALL_OUTPUT" >&2
    exit 1
fi

cat <<SUMMARY

All ${EXPECTED_CHECKS} end-to-end checks passed.

FreeScout:   ${FREESCOUT_URL}  (${ADMIN_EMAIL} / ${ADMIN_PASSWORD},
             ${NOCAP_EMAIL} / ${NOCAP_PASSWORD})
WordPress:   ${SPORTSPRESS_URL}

Tear down with: docker compose -p spatbridge-e2e down -v
SUMMARY
