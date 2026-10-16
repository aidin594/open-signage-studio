# OpenSignage Studio

**Digital signage for WordPress and WooCommerce — without a separate Node.js service.**

![WordPress](https://img.shields.io/badge/WordPress-6.3%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)
![Status](https://img.shields.io/badge/Status-Public%20beta-orange)

OpenSignage Studio turns a WordPress installation into a browser-based signage controller. Select in-stock WooCommerce products, create rich-text announcements, and display them in a fullscreen kiosk browser. Manage a playlist from the dashboard, a protected mobile panel, or an optional Telegram bot.

> **Public beta:** This project was white-labeled from a production-use installation. Static checks pass, but a complete clean-site integration and security review are still needed before claiming production readiness or publishing to the WordPress.org plugin directory.

## Features

- **WooCommerce product picker** — search published, in-stock products and select exactly which ones to show; prices and stock status sync.
- **Fullscreen product slides** — responsive product photos, short model names, naturally proportioned price typography, configurable colors.
- **Text slides and announcements** — six themes, formatting for individual words, highlight, and blink/soft-blink effects.
- **Timed overlays** — temporary announcements with automatic expiration or manual cancellation.
- **Playlist management** — arrange, enable, disable, pause, and select slides.
- **Private quick controls** — revocable token-based mobile access for messages and playlist operations.
- **Optional Telegram control** — restricted chats and users, webhook secret verification, interactive menus, quick buttons, and message scheduling.
- **Public view-only player** — stable kiosk URL; no player login or API key required.
- **WordPress-native stack** — PHP, REST API, browser JavaScript, and WordPress database; no separate Node.js process.

## Quick start

1. Download an **installable plugin ZIP** from GitHub Releases (not the source archive) or package the plugin directory using `scripts/package.sh`.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, install and activate OpenSignage Studio.
3. Open **OpenSignage Studio** in WordPress Admin.
4. Add text slides; optionally activate WooCommerce to add product slides.
5. Open the public display at `https://your-domain.example/signage-screen/` and use browser fullscreen (`F11`).
6. For mobile editing, generate a secure quick-control link under **Settings & access**. Configure Telegram only if needed.

**Requirements:** WordPress 6.3+, PHP 8.0+, HTTPS recommended and mandatory for Telegram. WooCommerce is needed only for product slides. A modern browser is required for the player/editor. Ensure WordPress REST endpoints are not cached or blocked. Enable WP-Cron or configure an external cron for scheduled product synchronization.

## How it works

```text
WordPress Admin ───────┐
WooCommerce products ──┼──► WordPress REST API / data ───► Browser display
Mobile quick control ─┤                                   /signage-screen/
Telegram (optional) ───┘
```

The player fetches a minimal read-only state from REST on an interval; slide rotation itself runs in the browser. Only privileged endpoints can mutate settings or content. The public display **shows whatever text you publish**, so do not place private information in slides or announcements.

## Important: independent public edition

The generic plugin is **not a drop-in update** to the former privately branded installation. It has its own plugin slug, database option keys, REST namespace, and URLs. It can be tested separately; it does **not** automatically import live plugin settings or Telegram credentials. Avoid configuring the *same Telegram bot token* on both installations: Telegram supports only one webhook per bot.

- Player: `/signage-screen/`
- Protected mobile controller: `/signage-control/` (generated key required for actions)
- REST namespace: `/wp-json/open-signage/v1/`
- Product data: read directly from WooCommerce; no WooCommerce REST consumer keys required

See [Architecture](docs/ARCHITECTURE.md), [Security](SECURITY.md), [Telegram setup](docs/TELEGRAM.md), [Migration](docs/MIGRATION.md) and [Changelog](CHANGELOG.md).

## Development and validation

The repository contains plain PHP, JS and CSS, with **no npm runtime dependency**. Validate locally with:

```bash
bash scripts/validate.sh
bash scripts/package.sh
```

GitHub Actions runs PHP syntax checks (8.0 and 8.4), JavaScript syntax checks, metadata checks, and a source hygiene scan. A green check is **not** a substitute for integration testing on a clean WordPress/WooCommerce installation.

## Security and privacy

- Public screen endpoints are read-only; management uses WordPress capabilities and nonce authentication.
- Telegram updates require a secret webhook header and an allowed chat/user list.
- Never commit `wp-config.php`, bot tokens, private links, exports, database dumps, or access tokens.
- Quick-control links function as bearer credentials. Treat them as passwords and rotate if disclosed.
- See [SECURITY.md](SECURITY.md) and [Privacy](docs/PRIVACY.md) before exposing an installation.

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). Please report suspected vulnerabilities privately instead of posting reproduction details to public issues.

## License

[GNU General Public License v2.0 or later](LICENSE). No proprietary assets, store-specific credentials, real customer data, or business-specific product imagery are bundled.
