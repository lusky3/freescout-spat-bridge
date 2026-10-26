# FreeScout SPAT Bridge

A FreeScout module that connects the ARL helpdesk (help.arl.hockey) to
SportsPress Admin Tools (SPAT) on the league's WordPress site. It is in
early development, and there is no release yet.

## What it will do

- Show league information for the ticket's customer in the conversation
  sidebar:
  - **Player card:** team, number, position, next game, registration
    history.
  - **e-Transfers:** matched and unmatched transfers, including possible
    matches for an on-hold order.
  - **Waitlist:** current waitlist entries. This replaces
    [freescout-splm-waitlist](https://github.com/lusky3/freescout-splm-waitlist).
- Let authorized agents fix common problems from the ticket, for example
  matching an e-Transfer to an order or re-offering a waitlist spot. Every
  action previews the change before it runs and is logged on the ticket as
  an internal note.
- Receive events from WordPress, such as an unmatched e-Transfer or a
  registration that didn't complete. Each event adds a note and a tag to
  the customer's open ticket. Creating a new ticket when none is open is
  optional and off by default.

The WordPress side ships in the
[SportsPress-Admin-Tools](https://github.com/lusky3/SportsPress-Admin-Tools)
plugins.

## Requirements

- Self-hosted FreeScout.
- PHP 7.4 or 8.0+.
- Network access from the FreeScout host to the WordPress site, and from
  the WordPress site to FreeScout for events.

## Install

The examples are for the `nfrastack/freescout` Docker image, where `Modules`
points to `/data/Modules` and the web server runs as `nginx:www-data`, in a
container named `freescout`. On another install, use its `Modules/` directory
and its web server user.

What has been checked: the Install steps and the Upgrading steps below were
run exactly as written on `nfrastack/freescout:2.2.21` (FreeScout 1.8.248,
PHP 8.5.12), using a release zip built with `git archive`, followed by the
21-check end-to-end suite.

Run every `php artisan` command as the web server user, never as root. Files
that root creates in FreeScout's cache are unwritable for the web server, and
the sidebar then shows nothing.

1. Get the module. Download `freescout-spat-bridge.zip` from the release, or
   build the same archive from a tag:

   ```sh
   git archive --format=zip --prefix=SpatBridge/ vX.Y.Z -o freescout-spat-bridge.zip
   ```

   The top-level folder must be exactly `SpatBridge`, to match the module's
   PHP namespace.

2. Extract it and copy it to `/data/Modules/SpatBridge`:

   ```sh
   unzip -q freescout-spat-bridge.zip
   docker cp SpatBridge freescout:/data/Modules/
   ```

3. Give the files to the web server user. `docker cp` leaves them owned by
   root:

   ```sh
   docker exec freescout chown -R nginx:www-data /data/Modules/SpatBridge
   ```

4. Restart the container: `docker restart freescout`, then wait until
   FreeScout has finished booting (about 15 seconds): the login page loads,
   or `docker logs freescout` shows "Initialization Complete". Running step 5
   too early fails with `Module [ARL League] does not exist!`; run it again
   once the boot has finished.
5. Enable and install the module as the web server user. A restart alone
   leaves it Disabled:

   ```sh
   docker exec -u nginx -w /www/html freescout php artisan module:enable "ARL League"
   docker exec -u nginx -w /www/html freescout php artisan freescout:module-install spatbridge
   ```

   `module:enable` takes the module's name in quotes. The folder name and the
   alias don't work there. `freescout:module-install` runs the module's
   database migration (with `--force`, so it isn't cancelled in production),
   links the module's scripts into `public/modules/spatbridge` and clears the
   cache. Together the two commands do what **Manage → Modules → ARL League →
   Activate** does.

6. Generate the shared secret:

   ```sh
   openssl rand -hex 32
   ```

   Use a new secret for this module. Don't reuse the one from
   freescout-splm-waitlist. Set the same value on the WordPress side.

7. Under **Manage → Settings → ARL League** (`/app-settings/spatbridge`), enter
   the WordPress site URL and the shared secret, then press **Test
   connection**. The test names the problem in plain words if the secret is
   wrong, missing on the WordPress side, or the clocks are out of step.

### Events from WordPress

The module can receive signed events from WordPress (unmatched e-Transfers,
incomplete registrations, waitlist offers) and turn them into internal notes,
tags and, optionally, new tickets. It never emails the customer.

1. Under **Manage → Settings → ARL League**, section **Events from
   WordPress**, copy the **Webhook URL for WP**.
2. In WordPress, under SportsPress Admin Tools → FreeScout, paste it into the
   SPAT events URL field and enable events. WordPress signs each event with
   the shared secret.
3. Choose the notes, tags, per-event tags and create switches, the mailbox for
   new tickets and who notes are posted as, then save.

Recent events, and what the module did with each, are listed at
`/app-settings/spatbridge/events` (admins only), linked from **Last event
received**.

Events are stored in a `spatbridge_events` table. On an install from before
events existed, the upgrade below creates it. Don't use plain
`php artisan migrate` for this: FreeScout runs in production mode, and over
`docker exec` without a terminal Laravel answers its own confirmation with no
and prints "Command Cancelled!". The table is then missing and every event
fails.

To check the install, `php artisan module:list` shows ARL League as Enabled,
and `https://<helpdesk>/modules/spatbridge/js/sidebar.js` returns 200. The
module survives restarts and redeploys as long as the `/data` and `/www/html`
volumes persist.

### Upgrading

Once the repository is public, **Manage → Modules → ARL League → Update Now**
does all of this. To upgrade by hand, with the new release's
`freescout-spat-bridge.zip` extracted to `SpatBridge` as in Install steps 1
and 2:

1. Remove the old module folder. Copying over it would merge the two versions
   and keep files the new one dropped. Run steps 1 and 2 together: in between,
   the module's files are missing.

   ```sh
   docker exec freescout rm -rf /data/Modules/SpatBridge
   ```

2. Copy the new folder in and give it to the web server user:

   ```sh
   docker cp SpatBridge freescout:/data/Modules/
   docker exec freescout chown -R nginx:www-data /data/Modules/SpatBridge
   ```

3. Install it as the web server user. This runs any new migrations (with
   `--force`), relinks the scripts and clears the cache:

   ```sh
   docker exec -u nginx -w /www/html freescout php artisan freescout:module-install spatbridge
   ```

   The module stays enabled; `module:enable` isn't needed again.

4. Restart the container, as in Install step 4, so PHP drops the old code:
   `docker restart freescout`. Wait for FreeScout to finish booting (the login
   page loads) before running any further `php artisan` command.

### Migrating from SplmWaitlist

The bridge's Waitlist feature replaces the older standalone SplmWaitlist
module (freescout-splm-waitlist). While both are active, agents see two
waitlist panels, and the ARL League settings page shows a warning.

1. Enable the bridge's Waitlist feature under **Manage → Settings → ARL
   League**, section **Modules**.
2. Compare the two panels on a few real tickets.
3. Disable SplmWaitlist first, and only then remove its files. The module
   registers its sidebar hook while it is loaded, so deleting it while it is
   active leaves a stale activation. Under **Manage → Modules → SportsPress
   Waitlist Status**, press **Deactivate**. Or from the shell, as the web
   server user and never as root:

   ```sh
   docker exec -u nginx -w /www/html freescout php artisan module:disable "SportsPress Waitlist Status"
   docker exec -u nginx -w /www/html freescout php artisan freescout:clear-cache
   ```

   Then remove the module folder and its leftover public symlink if present
   (`freescout:module-install` created it), and restart the container:

   ```sh
   docker exec freescout rm -rf /data/Modules/SplmWaitlist
   docker exec freescout rm -f /www/html/public/modules/splmwaitlist
   docker restart freescout
   ```
4. Later, a SPAT release removes the old `splm/v1/waitlist/customer-status`
   route and the `splm_freescout_secret` option. Until then, leave them alone.

### Development install (git clone)

For development, clone the repository instead of extracting a release, then
continue from Install step 3 (ownership). Install step 5 is still needed: a
clone also has to be enabled and installed with `freescout:module-install`.

```sh
git clone https://github.com/lusky3/freescout-spat-bridge.git Modules/SpatBridge
```

A clone includes the tests and dev harnesses, which releases leave out.

## Development

`scripts/setup-dev-env.sh` starts a local FreeScout instance in `docker/` to
test the module against. It clones FreeScout core into `docker/freescout`,
creates its `.env`, and starts the containers. It needs Docker with the
`docker compose` plugin, and git.

```sh
scripts/setup-dev-env.sh
```

When the script finishes, open <http://localhost:8083/install> to finish
FreeScout's setup. The web server listens on port 8083 and MySQL on
127.0.0.1:33063. The ports are chosen so this harness doesn't collide with the
freescout-splm-waitlist harness, which uses 8081 and 33062.

The compose file uses throwaway credentials and is for local testing only.

## End-to-end tests

`e2e/run-e2e.sh` starts a real WordPress site (sportspress-sandbox with the
SportsPress Admin Tools plugins) and a real FreeScout with this module, loads
known player, e-Transfer, waitlist and change-email fixtures, and checks
through `php artisan tinker`, and `wp` on the WordPress side, that:

- the connection test and the sidebar Player card work, including when
  WordPress is down;
- the e-Transfers panel shows the unmatched transfer and the on-hold order
  with its possible match;
- the match action previews first, asks for the amount-mismatch tick, runs
  once per idempotency key, and adds one internal note;
- WordPress refuses an agent whose WordPress account lacks the capability,
  and FreeScout refuses an agent the feature doesn't allow;
- the Waitlist panel shows the entries, and Offer, Withdraw offer, Remove and
  Re-offer work, with each offer emailed once (WordPress captures the mail);
- Change email refuses an address another login holds and an old address
  that isn't the ticket's customer's, then changes the player, the login and
  the order without an "email changed" notice;
- Re-run registration reports the outcome WordPress gives;
- events WordPress sends become one note each (a resent duplicate is
  ignored), open a ticket without emailing the customer when the type's
  create switch is on, and arrive on WordPress's retry after FreeScout was
  down.

Every artisan and tinker call runs as the PHP-FPM user (`www-data`), and the
last check asserts that FreeScout's cache holds nothing owned by root. The
script prints one `PASS` or `FAIL` line per check and exits non-zero on any
failure. The action checks change WordPress data, so each run recreates the
WordPress container. The sandbox turns WP-Cron off, so the script runs
`wp cron event run --due-now` itself whenever events should go out. It
repeats a pass until WP-CLI reports success, because WooCommerce's
background-process healthcheck events end a pass early with `exit`.

The sandbox's health endpoint reports ready while its setup is still
exporting the baseline. After every WordPress start, the script also waits
until supervisor logs that the sandbox's setup exited with status 0.

It expects
[sportspress-sandbox](https://github.com/lusky3/sportspress-sandbox) and
[SportsPress-Admin-Tools](https://github.com/lusky3/SportsPress-Admin-Tools)
checked out next to this repository. Point `SANDBOX_PATH` and `SPAT_PATH` at
other checkouts to test other branches. They can also be plain directories,
for example a snapshot of one commit, which keeps the test inputs fixed while
the checkouts change:

```sh
SANDBOX_PATH=~/src/sportspress-sandbox SPAT_PATH=~/src/SportsPress-Admin-Tools e2e/run-e2e.sh

mkdir -p /tmp/snap/spat
git -C ~/src/SportsPress-Admin-Tools archive <commit> | tar -x -C /tmp/snap/spat
SPAT_PATH=/tmp/snap/spat e2e/run-e2e.sh

docker compose -p spatbridge-e2e down -v   # tear down afterwards
```

WordPress listens on port 8094 and FreeScout on 8095. The E2E workflow runs
the same script on every pull request against the `main` branches of both
repositories. To test against unmerged branches, set the repository variables
`SPAT_REF` and `SANDBOX_REF` to those branch names, and delete the variables
once the branches merge. A manual run of the workflow takes the two refs as
inputs.

## Releasing

Releases are cut from `main`, and FreeScout's **Update Now** button uses them.

Every PR that changes shipped code adds a line under `## [Unreleased]` in
`CHANGELOG.md`. CI checks this. Files excluded from the release archive, such
as tests, `docker/`, and `scripts/`, don't need an entry.

To release version `X.Y.Z`:

1. In a release PR, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, add
   a fresh empty `## [Unreleased]` above it, and set `version` in
   `module.json` to `X.Y.Z`. The changelog check accepts this PR.
2. Merge the PR into `main`.
3. Tag `vX.Y.Z` on `main` and push the tag. The release workflow builds the
   archive and `version.json`, then publishes them. It refuses a tag whose
   version doesn't match `module.json`, has no changelog section, or isn't
   newer than the previous release.
4. Make the repository **public** before the first release. FreeScout's
   Update Now can't download from a private repository.

## License

MIT. See [LICENSE](LICENSE).
