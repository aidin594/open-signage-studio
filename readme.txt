=== OpenSignage Studio ===
Contributors: opensignage-contributors
Tags: digital signage, woocommerce, kiosk, telegram, display
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 0.1.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

OpenSignage Studio adds a fullscreen storefront display and management panel to WordPress, with optional WooCommerce and Telegram integrations.

== Description ==
Create product slides from in-stock WooCommerce items, rich-text announcements, timed temporary messages, and a configurable playlist. A public read-only player uses the stable /signage-screen/ URL. An optional protected mobile controller and Telegram webhook allow remote management.

Public beta: test on a staging installation before deployment. WooCommerce is optional for text slides; HTTPS is required for Telegram.

== Installation ==
1. Upload the OpenSignage Studio installation ZIP from WordPress Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open OpenSignage Studio in the WordPress admin.
4. Create text slides or select WooCommerce products.
5. Visit /signage-screen/ in a browser on the connected display.
6. Optionally generate a secure mobile-control link or register a Telegram webhook.

== Frequently Asked Questions ==
= Is WooCommerce required? =
Only for product-linked slides. Text slides and announcements work without it.

= Does the display require a login? =
No. The public display is view-only; do not publish private text on it.

= Is a separate Node.js service required? =
No. The WordPress REST API supplies state and the browser rotates slides.

= Why is the page blank? =
Add and enable at least one slide, confirm the REST API works, and exclude the signage REST routes from caching/security plugins.

= Can I control it from Telegram? =
Yes, using your own bot, a secret webhook, and allowlisted chat/user IDs.

== Changelog ==
= 0.1.0-beta.1 =
* First independent, English-language, white-label beta distribution.
* Namespaced plugin code and routes for side-by-side evaluation.
* No store-specific configuration or media included.
