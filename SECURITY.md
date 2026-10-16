# Security Policy

## Supported versions

| Version | Support |
| --- | --- |
| 0.1.0-beta.x | Security fixes during public beta; use on staging first |

## Reporting a vulnerability

**Do not post credentials, tokens, private links, or exploitable vulnerability details in public issues.** Prefer the repository's **Security → Report a vulnerability** (private vulnerability reporting) if enabled. Otherwise, contact the repository maintainer privately through GitHub.

## Exposure model

- The kiosk route `/signage-screen/` and its state endpoint are **public and read-only**. Anyone can view the current published content.
- WordPress-admin REST routes require sufficient capabilities; the UI sends a WordPress REST nonce.
- Mobile management requires a separate random bearer token, sent as a header. Rotating the control link invalidates old links.
- Telegram requires HTTPS, a bot token kept in WordPress options, a secret webhook header, and allowlisted chat/user IDs.
- Do not reuse a Telegram bot across production and staging: registration replaces the previous webhook.
- Do not disable REST authentication or publish any quick-control links in screenshots/issues.
- Restrict wp-admin access and keep WordPress, WooCommerce, PHP, and plugins updated.
- Player polling may be affected by caches. Exclude `/wp-json/open-signage/v1/*` from public-page caches and intermediary transforms.

## Public beta limitations

This project has not completed a professional third-party security audit or full cross-version integration test suite. Do not market it as audited or production-certified.
