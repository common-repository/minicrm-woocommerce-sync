<?php
/**
 * Author: MiniCRM
 * Author URI: https://www.minicrm.hu/
 * Description: Provides XML feed for MiniCRM webshop module.
 * Domain Path: /languages
 * License: Expat License
 * Plugin Name: MiniCRM Woocommerce Sync
 * Requires at least: 4.9
 * Requires PHP: 8.0
 * Text Domain: minicrm-woocommerce-sync
 * Version: 1.5.28
 * WC requires at least: 4.0.0
 * WC tested up to: 7.2
 * Tested up to: 6 (non-standard header for latest major WP version supported)
 *
 * Copyright 2018 Tamás Márton
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

use MiniCRM\WoocommercePlugin\Plugin;

// Prevent direct access
if (!defined ('ABSPATH')) {
    exit;
}

// Register namespace's autoloading
spl_autoload_register (function ($class) {
    $prefix = 'MiniCRM\\WoocommercePlugin\\';
    if (strpos ($class, $prefix) === 0) {
        $path = substr ($class, strlen ($prefix));
        $path = str_replace ('\\', DIRECTORY_SEPARATOR, $path);
        $path = __DIR__ . "/lib/$path.php";
        require_once $path;
    }
});

// Store plugin main file on Plugin class
Plugin::$mainFile = __FILE__;

// Set activation hook
register_activation_hook (__FILE__, [Plugin::class, 'activate']);

// Load translations
add_action (
    'plugins_loaded',
    function () {
        $textDomain = dirname (plugin_basename (__FILE__));
        load_plugin_textdomain ($textDomain, false, "$textDomain/languages/");
    },
    10
);

// Init plugin
add_action (
    'woocommerce_loaded',
    function () {
        Plugin::init ();
    },
    10,
    0
);

// Validate field mappings and display error message if missing fields found
add_action (
    'admin_init',
    function () {
        Plugin::validate_configured_fields ();
    },
    10,
    0
);
