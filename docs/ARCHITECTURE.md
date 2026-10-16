# Architecture

## Components

- `open-signage-studio.php` — plugin bootstrap, metadata, hooks.
- `includes/class-osgs.php` — REST routes, WordPress settings, product integration, playlists, messages, and player templates.
- `includes/class-osgs-telegram.php` — Telegram transport, authorization, callback routing, session state, and quick actions.
- `assets/admin.*` — WordPress admin UI.
- `assets/rich-editor.*` — safely constrained rich-text controls shared by the admin and mobile controller.
- `assets/player.*` — fullscreen browser player and polling.
- `assets/control.*` — token-protected mobile quick controller.

## WordPress data

The public edition stores data under its own `osgs_*` option/transient/meta prefixes and registers a private post type `osgs_slide`. It is separate from any independently installed signage plugins and does not read their data. Product prices and stock are read from WooCommerce. Change hooks trigger synchronization; an interval job is a fallback.

## Screen updates

The player checks `/wp-content/uploads/osgs-signal/revision.json` every 3 seconds. Nginx/Apache can serve this tiny static file without starting PHP. An updated revision triggers `/wp-json/open-signage/v1/screen/state`; WordPress is also contacted when timed messages expire or every 120 seconds as a recovery check. If the static file is unavailable, conventional REST polling resumes. Static HTTP requests continue; only dynamic WordPress work is reduced. Slide rotation remains entirely in the browser.

## Security boundaries

`screen/state` is public and read-only. Management routes require authenticated WordPress users with administrative or WooCommerce management privileges. Quick-control routes require a valid bearer key (or an authenticated admin with a valid REST nonce). Telegram requests must provide its webhook secret; individual chat/user IDs must be on allowlists. Never expose WordPress dashboard response data on the public screen route.

## Compatibility

This edition intentionally uses different slugs, class names, option names, and REST route prefixes from the original deployment. Both can coexist while testing, but the display URL and Telegram webhook cannot be shared between them without explicit migration.
