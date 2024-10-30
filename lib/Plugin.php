<?php

namespace MiniCRM\WoocommercePlugin;

// Prevent direct access
if (!defined ('ABSPATH')) {
    exit;
}

class Plugin
{
    /** @var string Name of query paramter to display "About" endpoint */
    const ACTION_QUERY_VAR = 'minicrm_woocommerce_action';

    /** @var string Plugin prefix for WP option names & form fields, etc. */
    const NAME = 'minicrm_woocommerce';

    /** @var string Name of query paramter to select project in feed */
    const FEED_QUERY_VAR = 'minicrm_woocommerce_feed_project';

    /** @var string Absolute path of the main plugin file, set by init() */
    public static $mainFile;

    /**
     * @var string[] A list of project IDs to be synced at the end of request.
     *               With the help of this we can avoid syncing the same project
     *               multiple times caused by the hooks running multiple times
     *               on a single event (eg. order update).
     */
    public static $projectsToSync = [];

    public static function activate ()
    {
        // Check if using a higher major WP version than supported
        $wpVer = get_bloginfo ('version');
        $wpVerParts = explode ('.', $wpVer);
        $wpMajorVer = (int) ($wpVerParts [0] ?? '0');
        $mainFileContents = file_get_contents (self::$mainFile);
        preg_match (
            '/\* Tested up to: (\d+)(\.\d+)*/',
            $mainFileContents,
            $matches
        );
        $testedUpToMajor = (int) ($matches [1] ?? '0');
        if ($wpMajorVer > $testedUpToMajor) {
            exit (sprintf (
                __(
                    'The plugin is not yet tested on Wordpress version %s.',
                    'minicrm-woocommerce-sync'
                ),
                $wpMajorVer
            ));
        }

        // Check if PHP XML extension is loaded
        if (!extension_loaded ('xml')) {
            exit (sprintf (
                __(
                    'PHP extension "%s" is required, but not loaded on your installation.',
                    'minicrm-woocommerce-sync'
                ),
                'xml'
            ));
        }
    }

    // Handle admin AJAX requests for "sync_projects" action
    public static function ajaxSyncProjects ()
    {
        if (current_user_can ('manage_options')) {
            $success = self::_syncProjects (
                $_GET ['projects'],
                $_GET ['test'] === '1'
            );

            // Success
            if ($success === true) {
                exit (0);
            }

            // Unexpected error
            http_response_code (500);
            exit (1);
        }

        // Forbidden
        http_response_code (403);
        exit (2);
    }

    /**
     * Returns all "wc-" prefixed WooCommerce order statuses plus "trash".
     * Use it to retrieve all orders with `wc_get_orders()` or `WC_Order_Query`,
     * including trashed ones (if You don't specifiy the status filter, trashed
     * ones are excluded). Other functions may fail using it.
     * `wc_orders_count()` accepts only the unprefixed order status and returns
     * zero for "trash" or "wc-" prefixed statuses!
     *
     * @return string[] All possible "shop_order" type post status codes
     */
    public static function getAllOrderStatuses (): array
    {
        return array_merge (array_keys (wc_get_order_statuses ()), ['trash']);
    }

    /**
     * @deprecated Only needed for migration purposes.
     * @return mixed A value of any type may be returned, including array,
     *               boolean, float, integer, null, object, and string.
     */
    public static function getOption (string $option)
    {
        return get_option (self::getOptionName ($option));
    }

    /** @deprecated Only used for migration.*/
    public static function getOptionName (string $option): string
    {
        return self::NAME . "_$option";
    }

    /**
     * @param int $orderId Order ID
     * @param int $customerId Customer ID (0 fot guest orders)
     *
     * @return int MiniCRM project ID to sync with
     *
     * @throws \Exception if registered user's order is >= GUEST_OFFSET
     *
     * TODO: It would make more sense to use a single $order arg of \WC_Order
     *       type, but we have to transform the feed to do that first...
     */
    public static function getOrderProjectId (
        int $orderId,
        int $customerId
    ): int
    {
        // Use offset-incremented order ID as project ID for guest orders
        if (!$customerId) {
            $project_id = $orderId + Configuration::GUEST_OFFSET;
        }

        // Use customer ID as project ID for registered users
        else {
            $project_id = $customerId;
            if ($project_id >= Configuration::GUEST_OFFSET) {
                $msg = "Registered user ID (#$project_id) is out of range";
                throw new \Exception ($msg);
            }
        }
        return $project_id;
    }

    /**
     * This function validates the fields mappings (WooCommerce data mapping)
     * @return void
     */
    public static function validate_configured_fields ()
    {
        $textValue = Integration::getOption ('wc_mapping');
        $mapping = Integration::getMapping ($textValue);
        $missingFields=array();

        $wco_Class = apply_filters( 'woocommerce_order_class', \WC_Order::class, 'order', 0 );

        foreach ($mapping as $wcField => $mcField) {
            $method = "get_$wcField";
            do_action( 'qm/debug',  $method);

            if( !method_exists ($wco_Class, $method) ) {
                array_push($missingFields, "$wcField:$mcField");
            }
        }

        if( count($missingFields) > 0) {
            do_action( 'ppppp-debug-notice' );
            add_action(
                'admin_notices',
                self::_ignoreTypeErrors (function () use(&$missingFields){
                    print('<div class="error notice"><p>'.
                        sprintf(__('<h3><strong>Error: MiniCRM Woocommerce Sync</strong></h3><strong>Sync will fail!</strong><br>Missing configured fields:<br>%s', 'minicrm-woocommerce-sync'),
                        join('<br>', $missingFields)
                    ).
                    '</p></div>');
                })
            );
        }
    }

    /** @return void */
    public static function init ()
    {
        /**
         * Queue projects for syncing upon creating/updating/trashing/untrashing
         * orders.
         *
         * NOTE: To sync on permanent deletion, hooking into "deleted_post"
         * would be needed. It would be more complicated to implement, since the
         * feed wouldn't find the order in the database anymore. However there
         * is no known way of deleting an order without trashing it on the
         * factory admin UI, and we would map the trashed/deleted order to the
         * same MiniCRM status, so we should be okay.
         */
        add_action (
            'woocommerce_new_order',
            self::_ignoreTypeErrors (

                /** @return void */
                function (int $orderId, \WC_Order $order = null)
                {
                    // Order arg is missing (only present in WC v3.7+), load from ID
                    if (is_null ($order)) {
                        $order = wc_get_order ($orderId);
                        if (!($order instanceof \WC_Order)) {
                            return;
                        }
                    }

                    self::_queueOrdersForSync ([$order]);
                }
            ),
            10,
            2
        );
        add_action (
            'woocommerce_update_order',
            self::_ignoreTypeErrors (

                /** @return void */
                function (int $orderId, \WC_Order $order = null)
                {
                    // Order arg is missing (only present in WC v3.7+), load from ID
                    if (is_null ($order)) {
                        $order = wc_get_order ($orderId);
                        if (!($order instanceof \WC_Order)) {
                            return;
                        }
                    }

                    self::_queueOrdersForSync ([$order]);
                }
            ),
            10,
            2
        );
        add_action (
            'trashed_post',
            self::_ignoreTypeErrors (

                /** @return void */
                function (int $postId)
                {
                    $order = wc_get_order ($postId);
                    if (!($order instanceof \WC_Order)) {
                        return;
                    }
                    self::_queueOrdersForSync ([$order]);
                }
            ),
            10,
            1
        );
        add_action (
            'untrashed_post',
            self::_ignoreTypeErrors (

                /** @return void */
                function (int $postId, string $previousStatus)
                {
                    $order = wc_get_order ($postId);
                    if (!($order instanceof \WC_Order)) {
                        return;
                    }
                    self::_queueOrdersForSync ([$order]);
                }
            ),
            10,
            2
        );

        /**
         * Sync queued projects right before the end of the current WP process.
         * By this time no further data changes are expected.
         *
         * IDEA: Might be replaced with an async solution like ActionScheduler
         *       in order to minimize increase in admin/frontend page load time.
         */
        add_action (
            'shutdown',
            self::_ignoreTypeErrors (
                function () {
                    if (count (self::$projectsToSync)) {
                        self::_syncProjects (
                            implode (',', self::$projectsToSync),
                            false
                        );
                    }
                }
            ),
            10,
            0
        );

        /**
         * Check for legacy options & migrate if needed once plugins are loaded.
         *
         * @deprecated Remove migration process once it becomes unused.
         */
        add_action ('plugins_loaded', self::_ignoreTypeErrors ([Integration::class, 'migrate']), 10, 0);

        // Register WooCommerce integration settings
        add_filter (
            'woocommerce_integrations',
            self::_ignoreTypeErrors (
                function (array $integrations): array
                {
                    $integrations [] = Integration::class;
                    return $integrations;
                }
            ),
            10,
            1
        );

        // Add script & style for integration settings page
        add_action (
            'admin_enqueue_scripts',
            self::_ignoreTypeErrors (function () {
                $screen = get_current_screen ();
                if ($screen->id === 'woocommerce_page_wc-settings') {
                    $scriptHandle = self::NAME . '_settings_script';
                    try {
                        $pluginVersion = About::getPluginVersion ();
                    } catch (\Throwable $e) {
                        $pluginVersion = null;
                    }
                    wp_enqueue_script (
                        $scriptHandle,
                        plugins_url ('/includes/settings.js' , self::$mainFile),
                        [],
                        $pluginVersion,
                        true
                    );
                    $data = [
                        'ajaxUrl'       => admin_url ('admin-ajax.php'),
                        'projectIds'    => Integration::getProjectIds (),
                        'timeoutInSecs' => Feed::TIMEOUT_IN_SECS,
                        'translations'  => [
                                  "The sync hasn't finished yet. Are you sure to leave and abort it?"
                            => __("The sync hasn't finished yet. Are you sure to leave and abort it?", 'minicrm-woocommerce-sync'),

                                  'An unexpected <strong class="minicrm-woocommerce-sync-error">error occured</strong>, the sync was aborted. (Please check the Sync log).'
                            => __('An unexpected <strong class="minicrm-woocommerce-sync-error">error occured</strong>, the sync was aborted. (Please check the Sync log).', 'minicrm-woocommerce-sync'),

                                  'Finished syncing all (%s) projects. <strong class="minicrm-woocommerce-sync-success">No error</strong> occured, but complete success can only be verified from the Sync log.'
                            => __('Finished syncing all (%s) projects. <strong class="minicrm-woocommerce-sync-success">No error</strong> occured, but complete success can only be verified from the Sync log.', 'minicrm-woocommerce-sync'),

                                  '<strong>Syncing</strong>... %s% Remaining time: %s Keep the window open to finish.'
                            => __('<strong>Syncing</strong>... %s% Remaining time: %s Keep the window open to finish.', 'minicrm-woocommerce-sync'),
                        ],
                    ];
                    $dataJson = json_encode ($data);
                    $inlineScript = "minicrmWoocommerceSyncData=$dataJson;";
                    wp_add_inline_script (
                        $scriptHandle,
                        $inlineScript,
                        'before'
                    );
                    wp_enqueue_style (
                        self::NAME . '_settings_style',
                        plugins_url ('/includes/settings.css', self::$mainFile),
                        [],
                        $pluginVersion
                    );
                }
            })
        );

        // Add "sync_projects" admin AJAX action
        add_action (
            'wp_ajax_sync_projects',
            self::_ignoreTypeErrors ([__CLASS__, 'ajaxSyncProjects']),
            10,
            0
        );

        // Add "Settings" link to plugin list item
        add_filter (
            'plugin_action_links_' . plugin_basename (self::$mainFile),
            self::_ignoreTypeErrors (
                function (array $links): array {
                    $query = http_build_query ([
                        'page' => 'wc-settings',
                        'tab' => 'integration',
                        'section' => self::NAME,
                    ]);
                    $url = admin_url ("admin.php?$query");
                    $link = "<a href=\"$url\">" . __('Settings') . "</a>";
                    array_unshift ($links, $link);
                    return $links;
                }
            ),
            10,
            1
        );

        // Add custom query vars
        add_filter (
            'query_vars',
            self::_ignoreTypeErrors (
                function (array $queryVars): array {
                    return array_merge ($queryVars, [
                        self::ACTION_QUERY_VAR,
                        self::FEED_QUERY_VAR,
                    ]);
                }
            ),
            10,
            1
        );

        // Set up custom routes
        add_action (
            'template_redirect',
            self::_ignoreTypeErrors (

                /** @return void */
                function () {

                    // Action (About)
                    $action = get_query_var (self::ACTION_QUERY_VAR);
                    if ($action === 'about') {
                        About::display ();
                    }

                    // Feed
                    $feed = get_query_var (self::FEED_QUERY_VAR);
                    if ($feed !== '') {
                        Feed::display ();
                    }
                }
            ),
            10,
            0
        );
    }

    public static function isPluginActive (string $plugin): bool
    {
        $activePlugins = get_option ('active_plugins');
        $activePlugins = apply_filters ('active_plugins', $activePlugins);
        return in_array ($plugin, $activePlugins);
    }

    /** @return array|null NULL if any of the options are missing. */
    protected static function _getStoredOptions ()
    {
        $options = [];
        foreach (Configuration::REQUIRED_OPTIONS as $short_name) {

            // Get option value
            $value = Integration::getOption ($short_name);

            // Option missing
            if ($value === null) {
                return;
            }

            // Collect option value
            $options [$short_name] = $value;
        }

        // All set
        return $options;
    }

    /**
     * Wraps a function in a try-catch block, which ignores any \TypeError
     * instances, so an incompatible WordPress/WooCommerce version won't break
     * the shop's main functionality.
     */
    protected static function _ignoreTypeErrors (callable $function): callable
    {
        return function (...$args) use ($function) {
            try {
                return $function (...$args);
            } catch (\TypeError $e) {
                if (Integration::isDebuggingEnabled ()) {
                    $file = $e->getFile ();
                    $line = $e->getLine ();
                    $msg  = $e->getMessage ();
                    self::_log ("Ignored TypeError in file $file on line $line with message: $msg.");
                }
            }
        };
    }

    /** @return void */
    protected static function _log (string $message)
    {
        $entry = "[" . gmdate ('r') . "]\n$message\n\n";
        file_put_contents (__DIR__ . '/sync.log', $entry,  FILE_APPEND);
    }

    /**
     * @param \WC_Order[] $orders
     *
     * @return void
     */
    protected static function _queueOrdersForSync ($orders)
    {
        foreach ($orders as $order) {

            /**
             * Skip drafts (eg. newly created orders having a guest customer ID
             * before assigning the correct ID of signed-up customer, or
             * untrashed orders waiting in purgatory for further instructions)
             */
            if ($order->get_status () === 'draft') {
                continue;
            }

            // Get project ID from order data
            $projectId = self::getOrderProjectId (
                $order->get_id (),
                $order->get_customer_id ()
            );

            // Add to list if not already there
            if (!in_array ($projectId, self::$projectsToSync)) {
                self::$projectsToSync [] = $projectId;
            }
        }
    }

    /**
     * @param string $project_ids Comma-separated list of project IDs or "all"
     * @param bool   $test        If using test server
     *
     * @return bool Was the sync request successful?
     */
    protected static function _syncProjects (
        string $project_ids,
        bool $test
    ): bool
    {
        $options = self::_getStoredOptions ();

        // Fail if options are incomplete
        if (is_array ($options) !== true) {
            return false;
        }

        // Check if debug
        $isDebuggingEnabled = Integration::isDebuggingEnabled ();

        //generating access secret and saving it in memory
        $secret = bin2hex (random_bytes (16));
        set_transient ($secret, true, 60 * 60 * 6);

        // Init feed URL
        $params = [
            "secret" => $secret,
            self::FEED_QUERY_VAR => "$project_ids.xml"
        ];
        $query = http_build_query ($params);
        $feedUrl = site_url () . "/?$query";

        // Send sync request to MC server
        $query = http_build_query (['Source' => $feedUrl]);
        $domain = "r3" . ($test ? '-test' : '') . ".minicrm.hu";
        $url = "https://$domain/Api/SyncFeed/$options[system_id]?$query";
        $auth_str = "$options[system_id]:$options[api_key]";
        $response = wp_remote_get (
            $url,
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode ($auth_str),
                ],
                'timeout' => Feed::TIMEOUT_IN_SECS,
            ]
        );

        // Fail
        if (is_wp_error ($response)) {
            if ($isDebuggingEnabled) {
                self::_log ($response->get_error_message ());
            }
            return false;
        }

        // Success
        if ($isDebuggingEnabled) {
            self::_log ('Successful sync request.');
        }
        return true;
    }
}
