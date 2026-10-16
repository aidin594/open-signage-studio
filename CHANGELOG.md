# Changelog

All notable changes to the **public** edition are documented here. Version numbers do not match the privately branded predecessor.

## [0.1.0-beta.2] - 2026-10-16

- Add static revision checks for fast Telegram updates without frequent WordPress boots.
- Fetch full REST state only on changes, timed-message expiry, or 120-second recovery.
- Fall back to conventional REST polling when static signals are unavailable.
- Extend source tests for marker publishing and player fallback behavior.

## [0.1.0-beta.1] - 2026-10-16

- Initial white-label public beta based on the established standalone WordPress implementation.
- Neutral branding and English interface with independent REST namespace and settings.
- Product picker, slides, formatted announcements, secure quick controls, and optional Telegram integration.
- Added documentation, release packaging, security guidance, and GitHub Actions lint checks.
- Removed store-specific content and examples; no real data or secrets distributed.

**Known limitations:** no automated end-to-end WordPress + WooCommerce + Telegram test suite; public player state is intentionally viewable without authentication; localization infrastructure is not yet complete.
