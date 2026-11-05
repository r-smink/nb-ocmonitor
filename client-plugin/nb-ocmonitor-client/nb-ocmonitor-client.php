<?php
/**
 * Plugin Name:       NB OC Monitor Client
 * Description:       Koppelt deze site aan het NB OC Monitor-panel van Next Buzz voor beheer en monitoring. Alle verzoeken zijn ondertekend (Ed25519).
 * Version:           0.11.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Next Buzz
 * Author URI:        https://www.nextbuzz.nl
 * License:           Proprietary
 * Text Domain:       nb-ocmonitor-client
 */

defined('ABSPATH') || exit;

define('NB_OCM_VERSION', '0.11.0');
define('NB_OCM_FILE', __FILE__);

if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>NB OC Monitor vereist PHP 7.4 of hoger.</p></div>';
    });
    return;
}

spl_autoload_register(static function (string $class): void {
    static $map = [
        'NextBuzz\\OcMonitor\\Protocol\\' => __DIR__ . '/lib/protocol/',
        'NextBuzz\\OcMonitor\\Client\\' => __DIR__ . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

NextBuzz\OcMonitor\Client\Plugin::boot(__FILE__);
