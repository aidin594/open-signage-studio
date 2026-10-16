# Migrating or evaluating alongside another signage plugin

OpenSignage Studio is **a standalone public fork**, not an in-place update for a privately branded plugin. It uses different storage (`osgs_*`), routes (`open-signage/v1`) and player/control URLs (`/signage-screen/`, `/signage-control/`).

- Take a full WordPress database and files backup before installing.
- Install it on staging or alongside the older plugin; do **not** remove the old plugin until verified.
- Create test slides and confirm the screen and read-only REST route work.
- Products can be selected again from WooCommerce. A legacy `slides.json` importer exists but must be tested with a sanitized export first.
- The new edition does not migrate stored Telegram tokens, user IDs, mobile control keys, or existing messages automatically.
- **Do not register the same Telegram bot on both versions**: the last webhook registration wins.
- Switch your signage browser to the new URL only after manual validation.
- Rollback: point the browser back to the old player URL, and deactivate this public plugin. Do not delete either installation's data until backups are confirmed.
