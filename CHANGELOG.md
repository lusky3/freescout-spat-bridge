# Changelog

All notable changes to this module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Module scaffold: `module.json`, service provider skeleton, option store,
  PHPUnit/PHPCS harness, CI workflows and the Docker dev environment.
- Feature contract, `Panel`/`PanelRow` model and `FeatureRegistry` with
  per-feature switches and ordering.
- Row actions on sidebar panels, with a per-module "Who can run actions"
  setting (Admins / Admins + Users / Selected people).
- Settings page (Connection, Modules, Sidebar) with a Test connection button.
- Conversation sidebar with stacked and tabbed layouts, a per-agent layout
  switch and a "League info unavailable" state.
- Read-only player card: one row per player with team, position, skill, next
  game, and registration attention and history.
- Release workflow and release guard: a release needs a version bump and a
  changelog entry, and PRs that change shipped files need a changelog entry.

### Fixed

- The settings page script carries FreeScout's CSP nonce and has no inline
  handlers, so Test connection, the secret's Show toggle and the card order
  buttons work in the browser.
- The player card says when WordPress reports the player or registration data
  as failed or not enabled, instead of silently leaving it out.
- Test connection names an outdated SportsPress Admin Tools (no FreeScout
  routes) and a site URL that redirects; redirects are no longer followed.
- The player card summary keeps the player and team when registration needs
  attention.
