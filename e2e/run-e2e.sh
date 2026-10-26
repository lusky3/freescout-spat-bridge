#!/usr/bin/env bash
# ==========================================================================
# run-e2e.sh -- bring up WordPress (sportspress-sandbox + the SPAT plugins)
# and FreeScout (with this module), then assert headlessly, through
# `php artisan tinker`, that the sidebar Player card, the e-Transfers panel
# and the action framework (preview, run, permissions, notes) work end to end.
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
EXPECTED_CHECKS=10

# php-fpm's pool user in the php:fpm base image.
FPM_USER="www-data"
APP_EXEC="$COMPOSE exec -T -u $FPM_USER"
FIXTURES=""

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# Runs PHP from stdin in tinker as the FPM user. HOME points psysh's config
# and history at a directory that user can write.
tinker() {
    $APP_EXEC -e HOME=/tmp "$@" freescout-app php artisan tinker
}

# Runs e2e/assert.php in a fresh tinker process for the given phase. psysh
# echoes piped input, but the input line holds no PASS/FAIL text, so only the
# script's own output can match the grep in the caller.
run_assertions() {
    tinker -e SPATBRIDGE_E2E_PHASE="$1" -e SPATBRIDGE_E2E_FIXTURES="$FIXTURES" <<'PHP' 2>&1 || true
require '/opt/spatbridge-e2e/assert.php';
PHP
}

wait_for_wordpress() {
    log "Waiting for sportspress-test to report healthy..."
    for i in $(seq 1 40); do
        if curl -sf "${SPORTSPRESS_URL}/wp-json/test/v1/health" | grep -q '"status":"ready"'; then
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
    grep -q '^APP_TRUSTED_HOSTS=' freescout-core/.env || echo 'APP_TRUSTED_HOSTS=localhost,freescout-web' >> freescout-core/.env
fi

# --------------------------------------------------------------------
# 2. Build + start everything, with a fresh WordPress
# --------------------------------------------------------------------
log "docker compose up -d --build (WordPress from ${SANDBOX_PATH}, SPAT from ${SPAT_PATH})"
$COMPOSE up -d --build --force-recreate sportspress-test
$COMPOSE up -d --build

# --------------------------------------------------------------------
# 3. Wait for sportspress-test's own healthcheck
# --------------------------------------------------------------------
wait_for_wordpress

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

log "Creating the WordPress fixtures (also stores the secret on the WordPress side)"
# The script prints one JSON line with the fixture ids; assert.php reads it
# from SPATBRIDGE_E2E_FIXTURES.
FIXTURES=$($COMPOSE exec -T sportspress-test bash /usr/local/bin/fixtures-freescout-bridge.sh "$SECRET" | tail -n 1)
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
OUTPUT_UP=$(run_assertions main)

log "Stopping sportspress-test, then running the WordPress-down assertion"
$COMPOSE stop sportspress-test
OUTPUT_DOWN=$(run_assertions wp_down)

log "Starting sportspress-test again for the e-Transfers and action assertions"
$COMPOSE start sportspress-test
wait_for_wordpress
OUTPUT_ACTIONS=$(run_assertions actions)

# Read as root so nothing is hidden by permissions; nothing is written.
log "Checking storage/framework/cache for root-owned entries"
ROOT_OWNED=$($COMPOSE exec -T freescout-app find storage/framework/cache -user root 2>&1 || true)
if [ -z "$ROOT_OWNED" ]; then
    OUTPUT_CACHE="PASS cache_not_root_owned"
else
    OUTPUT_CACHE="FAIL cache_not_root_owned: $(printf '%s' "$ROOT_OWNED" | head -5 | tr '\n' ' ')"
fi

ALL_OUTPUT=$(printf '%s\n%s\n%s\n%s\n' "$OUTPUT_UP" "$OUTPUT_DOWN" "$OUTPUT_ACTIONS" "$OUTPUT_CACHE")
RESULTS=$(printf '%s\n' "$ALL_OUTPUT" | grep -E '^(PASS|FAIL) ' || true)

log "Results"
printf '%s\n' "$RESULTS"

PASSES=$(printf '%s\n' "$RESULTS" | grep -c '^PASS ' || true)
if printf '%s\n' "$RESULTS" | grep -q '^FAIL ' || [ "$PASSES" -ne "$EXPECTED_CHECKS" ]; then
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
