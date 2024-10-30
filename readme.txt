=== MiniCRM WooCommerce Sync ===
Contributors: minicrmio
License: Expat License
License URI: https://directory.fsf.org/wiki/License:Expat
Requires at least: 4.9
Requires PHP: 8.0
Stable tag: 1.5.28
Tested up to: 6.2
WC requires at least: 4.0.0
WC tested up to: 7.2

Synchronizes WooCommerce orders with your MiniCRM webshop module.

== Description ==

The plugin synchronizes orders on the following events (when all required
options are filled in settings).

*   All orders are synchronized whenever the **plugin settings are saved**.
*   All orders are synchronized whenever the **plugin is activated**.
*   New orders created by your customers are synchronized.
*   Orders created or updated by administrators are synchronized. Orders are synchronized too, when an admin updates multiple orders with checkboxes.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/minicrm-woocommerce` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress admin.
1. Use the WooCommerce->Settings screen (Settings link on plugin too) and the "MiniCRM feed" section, to set your MiniCRM system's details.
1. You find your **System ID** in your browser's address bar after logging in to your MiniCRM account. For example, if you see https://r3.minicrm.io/1234/#TodayView, then your System ID is 1234.
1. You can generate an **API Key** in your MiniCRM account on the Settings(cogwheel)->System screen.
1. To find out your **Category ID**, select the Webshop module in your MiniCRM account. Use the number followed by `#!Project-` in the address bar. For example, if you see https://r3.minicrm.io/1234/#!Project-21, then your Category ID is 21.

== Changelog ==

- Initial public release
