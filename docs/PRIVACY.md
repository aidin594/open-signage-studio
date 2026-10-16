# Privacy notes

This plugin is intended to control a public display. Treat anything in a published slide or active message as public information. The display state endpoint is intentionally world-readable; no screen login is required.

Telegram integration transmits bot commands, allowed chat/user IDs and message text through Telegram's API. A bot token and access control IDs are stored as WordPress options. A temporary sender/session state and the latest public-screen activity timestamp may be stored in WordPress to support operation. The plugin itself does not bundle any analytics trackers or export product data to a third-party service, except as needed for Telegram messages when Telegram is configured. WooCommerce product information stays in WordPress until displayed as public slide content.

Consult your site's privacy policy and data retention requirements before enabling Telegram or displaying customer information. Never publish a real customer phone number, address, or personal message without permission.
