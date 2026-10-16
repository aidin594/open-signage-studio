<?php
/**
 * Plugin Name: OpenSignage Studio
 * Description: White-label digital signage for WooCommerce, with playlists, formatted announcements and optional Telegram control.
 * Version: 0.1.0-beta.2
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: OpenSignage Contributors
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: open-signage-studio
 */
if (!defined('ABSPATH')) { exit; }
define('OSGS_VERSION', '0.1.0-beta.2');
define('OSGS_FILE', __FILE__);
define('OSGS_PATH', plugin_dir_path(__FILE__));
define('OSGS_URL', plugin_dir_url(__FILE__));
/** UTF-8 safe clipping, even if the optional mbstring extension is unavailable. */
function osgs_clip(string $value, int $limit): string {
    if (function_exists('mb_substr')) return mb_substr($value, 0, $limit);
    if (preg_match('/^.{0,' . max(0,$limit) . '}/us', $value, $match)) return $match[0];
    return substr($value, 0, $limit);
}
require_once OSGS_PATH . 'includes/class-osgs.php';
require_once OSGS_PATH . 'includes/class-osgs-telegram.php';
register_activation_hook(__FILE__, ['OSGS', 'activate']);
register_deactivation_hook(__FILE__, ['OSGS', 'deactivate']);
add_action('plugins_loaded', ['OSGS', 'bootstrap']);
