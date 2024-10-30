<?php

namespace MiniCRM\WoocommercePlugin;

// Prevent direct access
if (!defined ('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/tail.php';

class About extends AbstractXmlEndpoint
{
    const SYNC_LOG_ENTRY_COUNT = 100;

    /**
     * @throws \Exception if fails to retrieve version from plugin main file.
     * @throws \TypeError if the header returns non-string version.
     */
    public static function getPluginVersion (): string
    {
        if (!isset (Plugin::$mainFile)) {
            throw new \Exception ('Plugin::$mainFile is unset.');
        }
        $headers = get_file_data (Plugin::$mainFile, ['v' => 'Version']);
        if (empty ($headers ['v'])) {
            throw new \Exception ("Failed to read plugin version.");
        }
        return $headers ['v'];
    }

    /** @throws \Exception */
    protected static function _buildXml (): \SimpleXMLElement
    {
        // Init XML
        $xmlStr = '<?xml version="1.0" encoding="utf-8"?><About/>';
        $about = new \SimpleXMLElement ($xmlStr);

        // Log plugins
        $plugins = get_option ('active_plugins', null);
        if (!is_array ($plugins)) {
            throw new \Exception ('Unexpected type for active plugins option.');
        }
        foreach ($plugins as $i => $plugin) {
            $about->ActivePlugins->Plugin [$i] = $plugin;
        }

        // Log plugin file hashes
        $files = [
            'main'                => Plugin::$mainFile,
            'About'               => __DIR__ . '/About.php',
            'AbstractXmlEndpoint' => __DIR__ . '/AbstractXmlEndpoint.php',
            'Configuration'       => __DIR__ . '/Configuration.php',
            'Feed'                => __DIR__ . '/Feed.php',
            'Plugin'              => __DIR__ . '/Plugin.php',
        ];
        foreach ($files as $node => $file) {
            $hash = md5_file ($file);
            if ($hash === false) {
                throw new \Exception ("Failed to calc md5 hash for: $file.");
            }
            $about->Hashes->{$node} = $hash;
        }

        // Log the file which already sent HTTP headers (if any)
        $about->HeadersWarning = null;
        $HeadersSent = headers_sent ($HeadersSentFrom);
        if ($HeadersSent === true) {
            $about->HeadersWarning = "Headers already sent from file: $HeadersSentFrom";
        }

        // INI configuration
        $iniDirectives = [
            'auto_append_file',
            'auto_prepend_file',
            'max_execution_time',
            'max_input_time',
            'memory_limit',
            'output_buffering',
        ];
        foreach ($iniDirectives as $directive) {
            $about->IniConfiguration->{$directive} = ini_get ($directive);
        }

        // Output buffering level
        $about->OutputBufferingLevel = ob_get_level ();

        // Log PHP version
        $about->PHPVersion = phpversion ();

        // Log plugin version
        $about->PluginVersion = self::getPluginVersion ();

        // Shop info
        $orderCount = 0;
        foreach (Configuration::ORDER_STATUS_MAP as $wcStatus => $mcStatus) {
            $orderCount += wc_orders_count ($wcStatus);
        }
        $userCount = count_users (); // Excludes guests, includes non-customers.
        $about->ShopInfo->OrdersCount = $orderCount;
        $about->ShopInfo->ProjectsCount = $userCount ['total_users'];

        // Log system info
        $about->SystemInfo->Hostname = php_uname ('n');
        $about->SystemInfo->KernelName = php_uname ('s');
        $about->SystemInfo->KernelRelease = php_uname ('r');
        $about->SystemInfo->KernelVersion = php_uname ('v');
        $about->SystemInfo->Machine = php_uname ('m');

        // Log WooCommerce version
        $about->WCVersion = $GLOBALS ['woocommerce']->version;

        // Log Wordpress version
        $about->WPVersion = $GLOBALS ['wp_version'];

        // Add latest sync log entries
        $about->LatestSyncLogEntries = tail (
            __DIR__ . '/sync.log',
            self::SYNC_LOG_ENTRY_COUNT
        );

        return $about;
    }
}
