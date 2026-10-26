#!/usr/bin/env bash
# ==========================================================================
# run-e2e.sh -- bring up WordPress (sportspress-sandbox + the SPAT plugins)
# and FreeScout (with this module), then assert headlessly, through
# `php artisan tinker`, that the sidebar Player card works end to end.
#
# Modeled on freescout-sportspress-e2e's run-e2e.sh. Safe to re-run:
# FreeScout core is cloned only if missing, migrations are idempotent, the
# admin user is created only once, and the WordPress fixtures are guarded.
#
# Sibling checkouts (override with environment variables):
#   SANDBOX_PATH  default ../../sportspress-sandbox        (relative to e2e/)
#   SPAT_PATH     default ../../SportsPress-Admin-Tools    (relative to e2e/)
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
EXPECTED_CHECKS=4

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# Runs e2e/assert.php in a fresh tinker process for the given phase. psysh
# echoes piped input, but the input line holds no PASS/FAIL text, so only the
# script's own output can match the grep in the caller.
run_assertions() {
    $COMPOSE exec -T -e SPATBRIDGE_E2E_PHASE="$1" freescout-app php artisan tinker <<'PHP' 2>&1 || true
require '/opt/spatbridge-e2e/assert.php';
PHP
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
# 2. Build + start everything
# --------------------------------------------------------------------
log "docker compose up -d --build (WordPress from ${SANDBOX_PATH}, SPAT from ${SPAT_PATH})"
$COMPOSE up -d --build

# --------------------------------------------------------------------
# 3. Wait for sportspress-test's own healthcheck
# --------------------------------------------------------------------
log "Waiting for sportspress-test to report healthy..."
for i in $(seq 1 40); do
    if curl -sf "${SPORTSPRESS_URL}/wp-json/test/v1/health" | grep -q '"status":"ready"'; then
        echo "sportspress-test is ready."
        break
    fi
    if [ "$i" -eq 40 ]; then
        echo "sportspress-test did not become healthy in time." >&2
        exit 1
    fi
    sleep 6
done

# --------------------------------------------------------------------
# 4. FreeScout install, entirely via artisan -- no browser wizard
# --------------------------------------------------------------------
log "Generating FreeScout APP_KEY (if not already set)"
if grep -q '^APP_KEY=base64:' freescout-core/.env; then
    echo "APP_KEY already set, skipping."
else
    $COMPOSE exec -T freescout-app php artisan key:generate --force
fi

log "Running FreeScout migrations"
$COMPOSE exec -T freescout-app php artisan migrate --force

log "Creating FreeScout admin user (skipped if it already exists)"
# psysh echoes piped input, so count rows rather than grep for a sentinel
# that also appears in the echoed source line. The trailing "\n" keeps psysh
# from appending its return glyph to the number.
USER_COUNT=$($COMPOSE exec -T freescout-app php artisan tinker <<'PHP' 2>/dev/null | grep -E '^[0-9]+$' | tail -1
echo \App\User::where('email', 'admin@example.test')->count() . "\n";
PHP
)
USER_COUNT="${USER_COUNT:-0}"
if [ "$USER_COUNT" -eq 0 ]; then
    $COMPOSE exec -T freescout-app php artisan freescout:create-user \
        --role=admin --firstName=Admin --lastName=User \
        --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" -n
else
    echo "Admin user already exists, skipping."
fi

log "Marking FreeScout as installed (storage/.installed) and clearing cache"
$COMPOSE exec -T freescout-app touch storage/.installed
$COMPOSE exec -T freescout-app php artisan freescout:clear-cache

# --------------------------------------------------------------------
# 5. Enable the module
# --------------------------------------------------------------------
log "Enabling the SpatBridge module"
# FreeScout's source of truth for activation is the `modules` table
# (App\Module::setActive). freescout:module-install then runs the module's
# migrations and symlinks public/modules/spatbridge to the module's Public/
# directory, which is where the `javascripts` filter points sidebar.js.
$COMPOSE exec -T freescout-app php artisan tinker <<'PHP' >/dev/null
\App\Module::setActive('spatbridge', true);
PHP
$COMPOSE exec -T freescout-app php artisan freescout:module-install spatbridge
if ! $COMPOSE exec -T freescout-app test -f public/modules/spatbridge/js/sidebar.js; then
    echo "public/modules/spatbridge/js/sidebar.js is missing after module install." >&2
    exit 1
fi

# --------------------------------------------------------------------
# 6. Shared secret, WordPress fixtures, module options
# --------------------------------------------------------------------
log "Generating a shared secret"
SECRET=$(openssl rand -hex 32)

log "Creating the WordPress fixtures (also stores the secret on the WordPress side)"
$COMPOSE exec -T sportspress-test bash /usr/local/bin/fixtures-freescout-bridge.sh "$SECRET"

log "Configuring the module options"
$COMPOSE exec -T freescout-app php artisan tinker <<PHP >/dev/null
\Option::set('spatbridge.wp_base_url', 'http://sportspress-test');
\Option::set('spatbridge.shared_secret', '$SECRET');
PHP
$COMPOSE exec -T freescout-app php artisan freescout:clear-cache

# --------------------------------------------------------------------
# 7. Assertions: WordPress up, then WordPress down
# --------------------------------------------------------------------
log "Running assertions with WordPress up"
OUTPUT_UP=$(run_assertions main)

log "Stopping sportspress-test, then running the WordPress-down assertion"
$COMPOSE stop sportspress-test
OUTPUT_DOWN=$(run_assertions wp_down)

RESULTS=$(printf '%s\n%s\n' "$OUTPUT_UP" "$OUTPUT_DOWN" | grep -E '^(PASS|FAIL) ' || true)

log "Results"
printf '%s\n' "$RESULTS"

PASSES=$(printf '%s\n' "$RESULTS" | grep -c '^PASS ' || true)
if printf '%s\n' "$RESULTS" | grep -q '^FAIL ' || [ "$PASSES" -ne "$EXPECTED_CHECKS" ]; then
    echo >&2
    echo "End-to-end checks failed (${PASSES}/${EXPECTED_CHECKS} passed). Raw tinker output:" >&2
    printf '%s\n%s\n' "$OUTPUT_UP" "$OUTPUT_DOWN" >&2
    exit 1
fi

cat <<SUMMARY

All ${EXPECTED_CHECKS} end-to-end checks passed.

FreeScout:   ${FREESCOUT_URL}  (${ADMIN_EMAIL} / ${ADMIN_PASSWORD})
WordPress:   ${SPORTSPRESS_URL}  (stopped by the last check; start it again with
             ${COMPOSE} start sportspress-test)

Tear down with: docker compose -p spatbridge-e2e down -v
SUMMARY
