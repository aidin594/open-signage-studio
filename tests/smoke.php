<?php
/** Lightweight route contract smoke test; no WordPress installation required. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['routes'] = [];
function register_rest_route($namespace, $route, $options) {
    $GLOBALS['routes'][$route] = ['namespace' => $namespace] + $options;
}
require_once __DIR__ . '/../includes/class-osgs.php';
require_once __DIR__ . '/../includes/class-osgs-telegram.php';
OSGS::routes();
$routes = $GLOBALS['routes'];
if (count($routes) !== 19) { throw new RuntimeException('Expected 19 registered routes, got ' . count($routes)); }
foreach ($routes as $route => $details) {
    if ($details['namespace'] !== 'open-signage/v1') throw new RuntimeException('Unexpected REST namespace');
    if (!is_callable($details['callback']) || !is_callable($details['permission_callback'])) throw new RuntimeException('Bad REST callable: ' . $route);
}
foreach (['/dashboard','/settings','/slides','/message','/playlist','/telegram/config','/access'] as $route) {
    if (!isset($routes[$route])) throw new RuntimeException('Missing protected route: ' . $route);
    if ($routes[$route]['permission_callback'] !== [OSGS::class, 'can_manage']) throw new RuntimeException('Admin route has wrong permission: ' . $route);
}
if ($routes['/screen/state']['permission_callback'] !== [OSGS::class, 'screen_allowed']) throw new RuntimeException('Public screen permissions differ');
if ($routes['/quick/message']['permission_callback'] !== [OSGS::class, 'quick_allowed']) throw new RuntimeException('Quick control permissions differ');
if ($routes['/telegram/webhook']['permission_callback'] !== [OSGS_Telegram::class, 'allowed']) throw new RuntimeException('Telegram webhook permissions differ');
echo 'PASS: 19 REST routes; public display, admin, mobile and webhook permissions' . PHP_EOL;
