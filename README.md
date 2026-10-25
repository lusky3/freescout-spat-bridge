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

## License

MIT. See [LICENSE](LICENSE).
