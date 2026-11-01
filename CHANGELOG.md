# Changelog

All notable changes to this module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The sidebar looks the customer up in WordPress by every address FreeScout
  has for them: the main email, the conversation's customer address and the
  customer's other emails (trimmed, lower-cased, de-duplicated, at most 5).
  Requires SPAT with the list-based customer lookup
  (SportsPress-Admin-Tools #243); an older SPAT refuses the request.
- Change email… is offered on each player row, for the address WordPress
  found that player under (or the customer's primary address), and accepts
  any of the customer's addresses. The note after a change asks to add the
  new address to the customer in FreeScout.
- Waitlist rows name the registrant, and the address when it isn't the
  primary one, when a customer's entries belong to more than one person or
  address. A single registrant under the primary address looks as before.
- WordPress failures (bad signature, clock skew, not configured in
  WordPress, outdated SPAT, refused requests, outages and timeouts) are
  logged at `error`, which FreeScout's default log level keeps. Rate
  limiting and actions WordPress refused with a reason stay at `warning`.
  The module's own missing URL or secret is still not logged; the settings
  page shows it.
- README: Update Now no longer claims to do the whole manual upgrade (it
  installs over the existing folder and keeps files a release dropped), the
  boot-wait tip warns that "Initialization Complete" in the container log can
  be the previous boot's, the install step links the release zip, and the
  verification note covers the published 1.0.0 asset.

### Fixed

- Players, waitlist entries and e-Transfers recorded under a customer's
  secondary address now show on the ticket.
- A customer whose only waitlist entry is a lapsed offer gets the summary
  "S2026 Goalie offer expired" instead of "On the S2026 waitlist".
- The offer and change-email forms' validation messages are translatable.

## [1.0.0] - 2026-11-01

### Added

- Migration notes for SplmWaitlist in the README, and a settings-page warning
  while SplmWaitlist and the bridge are both active.
- Player card actions: Change email… on the first player row (previews every
  record that will change) and Re-run registration on each "registration not
  completed" row.
- Waitlist panel for the customer's SportsPress waitlist entries, with Offer,
  Re-offer, Withdraw offer and Remove from waitlist actions (replaces the
  separate SplmWaitlist module).
- "Events from WordPress" settings section (webhook URL with a copy button, note
  and tag switches, a tag and create switch per event, mailbox for new tickets,
  "Post notes as") and an admin-only event log page at
  `/app-settings/spatbridge/events`.
- Module scaffold: `module.json`, service provider skeleton, option store,
  PHPUnit/PHPCS harness, CI workflows and the Docker dev environment.
- Feature contract, `Panel`/`PanelRow` model and `FeatureRegistry` with
  per-feature switches and ordering.
- Row actions on sidebar panels, with a per-module "Who can run actions"
  setting (Admins / Admins + Users / Selected people).
- Action pop-up: shows WordPress's dry-run preview, runs the action as the
  agent's WordPress account once confirmed, and records the outcome as an
  internal note on the ticket.
- Settings page (Connection, Modules, Sidebar) with a Test connection button.
- Conversation sidebar with stacked and tabbed layouts, a per-agent layout
  switch and a "League info unavailable" state.
- Read-only player card: one row per player with team, position, skill, next
  game, and registration attention and history.
- e-Transfers panel: a customer's e-Transfers and on-hold orders, with
  "Match" actions (preview and confirm pop-up, order/transfer picker, extra
  tick when the amounts differ).
- Signed event receiver (`POST /spatbridge/events`) for WordPress events
  (e-Transfers, registrations, waitlist offers): each is logged once, adds an
  internal note and tag to the customer's open tickets, or, when switched on
  for that type, opens a phone ticket at the top of the queue without
  mailing the customer (customer mail is held back while the ticket has only
  notes; an agent's later reply is sent as usual). Old events are pruned
  daily after 90 days.
- Release workflow and release guard: a release needs a version bump and a
  changelog entry, and PRs that change shipped files need a changelog entry.

### Changed

- Registration outcomes added in SPAT 1.10.0 now read in plain language on the
  Player card (attention rows and history) and in event notes: a name match
  that conflicts with the record's account or email says it needs manual
  review, name-only matches that could not be verified say so, and a refused
  account link says the player belongs to another account. Other outcome codes
  read as before.
- README: the install steps are the production runbook (release archive into
  `/data/Modules/SpatBridge`, ownership fix, enable and install as the web
  server user), with what was actually checked and where. Cloning is kept as
  a development install.
- README: a manual upgrade section (remove the old folder, copy, ownership,
  `freescout:module-install` as the web server user, restart). The events
  table is created by `freescout:module-install`, not plain
  `php artisan migrate`, which production mode cancels over `docker exec`.
- README: the end-to-end section lists the waitlist, Player card action and
  WordPress event checks.
- E2E: the FreeScout logins are generated per run instead of hardcoded, and
  can be set with `E2E_ADMIN_PASSWORD` and `E2E_NOCAP_PASSWORD`.
- README: the end-to-end section names the sandbox workarounds the script
  still needs (repeated cron passes, waiting for the sandbox setup to exit).
- README: after `docker restart`, wait for FreeScout to finish booting before
  `module:enable` (run too early it fails with "Module [ARL League] does not
  exist!"); the verification note says the Install and Upgrading steps were
  run as written on `nfrastack/freescout:2.2.21`.

### Fixed

- Event tags are applied only when the Tags module's `attachByName` /
  `detachByName` have the expected static `(name, conversation id)`
  signature; otherwise tags are skipped ("tag skipped" in the event log) and
  a warning is logged.
- An event of a type this version doesn't know is kept in the event log as
  "Ignored (event type not known to this version)" and answered 200, so
  WordPress doesn't drop it as failed; no note or ticket is made.
- The event receiver refuses a body over 64 KB (413) before checking its
  signature.
- The event log no longer says "Note added" when event notes are switched
  off: it records "Tag updated, no note", "Tag skipped, no note" or, with
  notes and tags both off, "Nothing done".
- An action counts as done only when WordPress answers `success: true`; any
  other answer is shown as an error and adds no internal note.
- After Change email, the internal note says the ARL League card follows the
  ticket's email address, so the customer's email in FreeScout may need
  updating to see the card again.
- Sidebar dates (e-Transfers, waitlist) and the e-Transfer picker's results
  are shown in the viewing agent's FreeScout time zone instead of UTC; the
  waitlist offer deadline names its zone (e.g. "2:00 PM EDT"). Event notes
  give the waitlist deadline in FreeScout's app time zone, named.
- When a preview asked for from the Change email form, the waitlist Offer form
  or the e-Transfer picker is refused (for example, the email is already in
  use), the form stays open with the message in it, so the agent can correct
  it and try again.
- Each "Match" link on an on-hold order names the transfer's amount and
  sender ("Match $325.00 from Pat Smith"), so several possible matches can be
  told apart.
- The mailbox new event tickets go to is always in ARL League's scope, even
  when "Show in mailboxes" doesn't list it: later events find the ticket
  instead of opening another, and its sidebar and actions work.
- The settings page script carries FreeScout's CSP nonce and has no inline
  handlers, so Test connection, the secret's Show toggle and the card order
  buttons work in the browser.
- The player card says when WordPress reports the player or registration data
  as failed or not enabled, instead of silently leaving it out.
- Test connection names an outdated SportsPress Admin Tools (no FreeScout
  routes) and a site URL that redirects; redirects are no longer followed.
- The player card summary keeps the player and team when registration needs
  attention.
- Player card dates (next game, registration history) are shown in the viewing
  agent's FreeScout time zone, like the e-Transfers and Waitlist panels,
  instead of the zone WordPress sent.
- The settings page's "Last event received" is shown in the viewing agent's
  time zone with its abbreviation (e.g. "2026-10-26 09:40:24 EDT") instead of
  unlabelled server time.
- In the e-Transfer "Match to order" picker, an order that isn't on hold is
  shown with a disabled "Use this order" button instead of being refused only
  at the preview, and the order-number and search boxes have an accessible
  name.
- The tabbed sidebar layout keeps its tab labels on one line ("Waitlist" no
  longer wraps at the default sidebar width).
- The settings page's "Events table missing" hint now says to run
  `php artisan freescout:module-install spatbridge` as the web server user;
  plain `php artisan migrate` is cancelled in production.
- With event notes off and tags on, a type whose tag was cleared in settings
  is logged as "Nothing done" instead of "Tag updated, no note".
