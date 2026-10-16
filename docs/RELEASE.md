# Maintainer release checklist

1. Check that all code, default settings, placeholders and documentation are generic English and contain no business-specific URLs, contact details, screenshots or credentials.
2. Run `bash scripts/validate.sh`. Keep PHP 8.0 syntax compatible.
3. Test the ZIP on a **clean staging WordPress** with and without WooCommerce; test product sync, slide editor, fullscreen player, and display after expiry.
4. Test private control routes without keys and with revoked keys; verify Telegram webhook allowlist, secret validation, and idempotence.
5. Test REST caching exclusions. Do not expose dashboard settings through the public player response.
6. Build a fresh ZIP with `bash scripts/package.sh`, install and test it through WordPress → Plugins → Upload Plugin.
7. Tag the reviewed commit using `v0.1.0-beta.1` (or another matching version); the release workflow publishes the installable ZIP as a pre-release. Document any known limitations in the release notes.
8. Do not call the beta security-audited or WordPress.org-approved without a corresponding independent review.

Recommended repository settings: enable **Issues**, **Discussions** (optional), **Dependabot security alerts**, **Secret scanning** when available, and **private vulnerability reporting**. Protect `main` with required CI checks and require PR review before merging.
