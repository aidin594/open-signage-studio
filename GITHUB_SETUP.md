# GitHub repository launch settings

## Repository

- **Suggested name:** `open-signage-studio`
- **Visibility:** Public (once reviewed)
- **Description:** `Open-source digital signage for WordPress and WooCommerce, with a fullscreen kiosk player, rich-text slides, and optional Telegram control.`
- **Topics:** `wordpress`, `woocommerce`, `digital-signage`, `kiosk`, `telegram-bot`, `php`, `javascript`, `open-source`, `signage`, `retail`
- **License:** GPL-2.0-or-later (included)
- **Homepage:** leave blank unless a public demo is available

## First publish

1. Create an empty GitHub repository (do not auto-add README, .gitignore, or license; they are already included).
2. Upload all root files and folders of this source distribution, including the hidden `.github` folder.
3. Confirm `.github/workflows/ci.yml` runs and check both PHP matrix entries.
4. Review the [Security Policy](SECURITY.md), then enable private vulnerability reporting, branch protection and secret scanning if available for the account.
5. After integration tests on staging, tag `v0.1.0-beta.1`; the release workflow attaches an installable plugin ZIP to a pre-release.

## Suggested GitHub Release notes

### OpenSignage Studio v0.1.0-beta.1

A white-label WordPress signage plugin with optional WooCommerce and Telegram integrations. This is a **public beta** intended for staging and developer evaluation.

**Highlights:**
- Fullscreen browser player with stable, key-free view URL
- In-stock WooCommerce product selection and synchronization
- Configurable slides, rich-text announcements and timing
- Private mobile controller and authorized Telegram control panel
- Independent WordPress-native architecture; no Node.js daemon

**Important:** Published slides are publicly viewable. Telegram and mobile keys remain private. No real store data or credentials are included. The software has not completed a third-party security audit or full WordPress integration test matrix.

**Download:** Use the attached `open-signage-studio-0.1.0-beta.1.zip` installer, not GitHub's automatic source archive.
