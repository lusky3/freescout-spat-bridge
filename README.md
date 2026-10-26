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

1. Clone the repository into FreeScout's `Modules/` directory under the
   name `SpatBridge`:

   ```sh
   git clone https://github.com/lusky3/freescout-spat-bridge.git Modules/SpatBridge
   ```

2. Restart FreeScout so it registers the module. In a Docker install, restart
   the container.
3. Under **Manage → Modules**, enable **ARL League**.
4. Under **Manage → Settings → ARL League**, enter the WordPress site URL and
   the shared secret, then press **Test connection**. The test names the
   problem in plain words if the secret is wrong, missing on the WordPress
   side, or the clocks are out of step.

The shared secret must be at least 32 characters and must match the value on
the WordPress side. Use a new secret for this module. Don't reuse the one from
freescout-splm-waitlist.

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
a known player fixture, and checks through `php artisan tinker` that the
connection test and the sidebar Player card work, including when WordPress is
down. It prints one `PASS` or `FAIL` line per check and exits non-zero on any
failure. It expects
[sportspress-sandbox](https://github.com/lusky3/sportspress-sandbox) and
[SportsPress-Admin-Tools](https://github.com/lusky3/SportsPress-Admin-Tools)
checked out next to this repository. Point `SANDBOX_PATH` and `SPAT_PATH` at
other checkouts to test other branches:

```sh
SANDBOX_PATH=~/src/sportspress-sandbox SPAT_PATH=~/src/SportsPress-Admin-Tools e2e/run-e2e.sh
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
