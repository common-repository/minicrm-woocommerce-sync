<?php

namespace MiniCRM\WoocommercePlugin;

// Prevent direct access
if (!defined ('ABSPATH')) {
    exit;
}

class Integration extends \WC_Integration
{
    /**
     * Returns mapping array parsed from string setting. The array key
     * represents the WooCommerce field name/label, while the value holds the ID
     * for the custom field in the MiniCRM "Megrendelés" module.
     */
    public static function getMapping (string $textValue): array
    {
        $matchCount = preg_match_all (
            '/(^|\n|\r)([^:\n\r]+):([^:\n\r]+)(\n|\r|$)/',
            $textValue,
            $matches
        );
        if ($matchCount === false) {
            throw new \Exception ('Failed to match mapping pattern.');
        }
        $mapping = [];
        foreach ($matches [2] as $i => $label) {
            $mapping [trim ($label)] = trim ($matches [3] [$i]);
        }
        return $mapping;
    }

    public static function getOption (string $key): string
    {
        $instance = new self;
        return $instance->get_option ($key, '');
    }

    /**
     * Checks if "system_id" legacy option is set and migrates if it is (and new
     * options are unset). Note that it ignores checking other legacy options
     * (for performance reasons) in the absence of "system_id", so the user
     * might need to look them up again, but it won't break the sync, since
     * "system_id" is required for it to work.
     *
     * @deprecated Remove migration process once it becomes unused.
     */
    public static function migrate ()
    {
        // Check if migration is needed.
        $legacySystemId = Plugin::getOption ('system_id');
        if (empty ($legacySystemId)) {
            return;
        }

        // Init integration instance so we can update its options
        $integration = new self;

        // Read stored legacy options
        $legacyValues = [];
        foreach (Configuration::REQUIRED_OPTIONS as $option) {
            $value = Plugin::getOption ($option);
            if ($value === false) {
                return;
            }
            $legacyValues [$option] = $value;
        }

        // Store upgraded options
        foreach ($legacyValues as $option => $legacyValue) {
            $upgradedValue = $integration->get_option ($option);
            if (empty ($upgradedValue)) {
                $success = $integration->update_option ($option, $legacyValue);
                if ($success !== true) {
                    return;
                }
            }
        }

        // Delete legacy options
        foreach ($legacyValues as $option => $legacyValue) {
            $success = delete_option (Plugin::getOptionName ($option));
            if ($success !== true) {
                return;
            }
        }
    }

    /**
     * @return int[] Project IDs to sync
     */
    public static function getProjectIds ()
    {
        $projectIds = [];
        $filters = [
            'post_type'      => 'shop_order',
            'post_status'    => 'any',
            'posts_per_page' => 10,
            'paged'          => 1, // NOTE: Starts on 1, not 0
            'order'          => 'ASC',
            'orderby'        => 'ID',
            'fields'         => 'ids',
        ];
        do {
            $query = new \WP_Query ($filters);
            $orderIds = $query->get_posts ();
            foreach ($orderIds as $orderId) {
                $customerId = get_post_meta ($orderId, '_customer_user', true);
                $customerId = (int) $customerId;
                $projectId = Plugin::getOrderProjectId ($orderId, $customerId);
                if (!in_array ($projectId, $projectIds)) {
                    $projectIds [] = $projectId;
                }
            }
            $filters ['paged'] ++;
        } while ($query->have_posts ());
        return $projectIds;
    }

    public static function isDebuggingEnabled (): bool
    {
        return self::getOption ('debug') === 'on';
    }

    public function __construct ()
    {
        $this->id = Plugin::NAME;
        $this->method_title = 'MiniCRM';

        // HACK: Couldn't find a better way...
        $descPath = dirname (__FILE__, 2) . '/includes/settings-sync.php';
        if (ob_start () === true) {
            require $descPath;
            $desc = ob_get_clean ();
            if ($desc !== false) {
                $this->method_description = $desc;
            }
        }

        // Set the fields to display & handle
        $this->init_form_fields ();

        // This is probably needed for setting defaults (if there's any)
        $this->init_settings ();

        // Set up form saving. (Delete this and the user input is dumped.)
        add_action (
            'woocommerce_update_options_integration_' .  $this->id,
            [$this, 'process_admin_options']
        );
    }

    // NOTE: The method is inherited from \WC_Integration, so don't rename it.
    public function init_form_fields ()
    {
        $validWcFields = [

            // billing...
            'billing_address_1'             => __('Billing Address 1',        'woocommerce'),
            'billing_address_2'             => __('Billing Address 2',        'woocommerce'),
            'billing_city'                  => __('Billing City',             'woocommerce'),
            'billing_company'               => __('Billing Company',          'woocommerce'),
            'billing_country'               => __('Billing Country / Region', 'woocommerce'),
            'billing_email'                 => __('Billing email',            'woocommerce'),
            'billing_first_name'            => __('Billing First Name',       'woocommerce'),
            'billing_last_name'             => __('Billing Last Name',        'woocommerce'),
            'billing_phone'                 => __('Phone',                    'woocommerce'),
            'billing_postcode'              => __('Billing Postal/Zip Code',  'woocommerce'),
            'billing_state'                 => __('Billing State',            'woocommerce'),

            'customer_note'                 => __('Customer note', 'woocommerce'),

            // formatted...
            'formatted_billing_address'     => __('Full billing address',  'minicrm-woocommerce-sync'),
            'formatted_billing_full_name'   => __('Full billing name',     'minicrm-woocommerce-sync'),
            'formatted_shipping_address'    => __('Full shipping address', 'minicrm-woocommerce-sync'),
            'formatted_shipping_full_name'  => __('Full shipping name',    'minicrm-woocommerce-sync'),

            // payment_method...
            'payment_method'                => __('Payment method ID.',    'woocommerce'),
            'payment_method_title'          => __('Payment method title.', 'woocommerce'),

            // shipping...
            'shipping_address_1'            => __('Shipping Address 1',        'woocommerce'),
            'shipping_address_2'            => __('Shipping Address 2',        'woocommerce'),
            'shipping_city'                 => __('Shipping City',             'woocommerce'),
            'shipping_company'              => __('Shipping Company',          'woocommerce'),
            'shipping_country'              => __('Shipping Country / Region', 'woocommerce'),
            'shipping_first_name'           => __('Shipping First Name',       'woocommerce'),
            'shipping_last_name'            => __('Shipping Last Name',        'woocommerce'),
            'shipping_method'               => __('Shipping method title.',    'woocommerce'),
            'shipping_postcode'             => __('Shipping Postal/Zip Code',  'woocommerce'),
            'shipping_state'                => __('Shipping State',            'woocommerce'),
        ];

        $this->form_fields = [
            'system_id' => [
                'default'     => '',
                'desc_tip'    => __(
                    'The number following "r3.minicrm.io/" in the URL after logging into your MiniCRM account.',
                    'minicrm-woocommerce-sync'
                ),
                'placeholder' => __('eg. 12345', 'minicrm-woocommerce-sync'),
                'title'       => __('System ID', 'minicrm-woocommerce-sync'),
                'type'        => 'text',
            ],
            'shop_id' => [
                'default'     => 0,
                'desc_tip'    => __(
                    'If you sync multiple shops into a single MiniCRM account, you need to set a unique shop ID for each WooCommerce shop to avoid one shop overwriting another one\'s orders. If you sync a single shop only, then leave it on 0.',
                    'minicrm-woocommerce-sync'
                ),
                'title'       => __('Shop ID', 'minicrm-woocommerce-sync'),
                'type'        => 'select',
                'options'     => range (0, 99),
            ],
            'api_key' => [
                'default'     => '',
                'desc_tip'    => __("You can generate one in your MiniCRM account's Settings > System > API key > Create new API key", 'minicrm-woocommerce-sync'),
                'title'       => __('API key', 'minicrm-woocommerce-sync'),
                'type'        => 'password',
            ],
            'locale' => [
                'default' => '',
                'options' => [
                    'EN' => __('English'),
                    'HU' => __('Hungarian'),
                    'RO' => __('Romanian'),
                ],
                'title' => __('MiniCRM account locale', 'minicrm-woocommerce-sync'),
                'type'  => 'select',
            ],
            'category_id' => [
                'default'     => '',
                'desc_tip'    => __(
                    'The number following "#!Project-" in the URL after opening the Webshop module in your MiniCRM account',
                    'minicrm-woocommerce-sync'
                ),
                'placeholder' => __('eg. 21', 'minicrm-woocommerce-sync'),
                'title'       => __('Category ID', 'minicrm-woocommerce-sync'),
                'type'        => 'text',
            ],
            'folder_name' => [
                'default'     => '',
                'desc_tip'    => __(
                    'The MiniCRM folder name for products imported along with webshop orders.',
                    'minicrm-woocommerce-sync'
                ),
                'placeholder' => __(
                    'eg. Webshop products',
                    'minicrm-woocommerce-sync'
                ),
                'title'       => __('Folder name', 'minicrm-woocommerce-sync'),
                'type'        => 'text',
            ],
            'sync_product_desc' => [
                'default'     => 'yes',
                'title'       => __(
                    'Sync product descriptions',
                    'minicrm-woocommerce-sync'
                ),
                'type'        => 'checkbox',
            ],
            'debug' => [
                'default'  => '',
                'desc_tip' => __(
                    'Turning it on can help diagnose issues. It is recommended to turn it off during normal operation.',
                    'minicrm-woocommerce-sync'
                ),
                'options'  => [
                    'off' => __('Disabled'),
                    'on'  => __('Enabled'),
                ],
                'title' => __('Debug mode', 'woocommerce'),
                'type'  => 'select',
            ],
            'wc_mapping' => [
                'css' => 'font-family: monospace;',
                'default' => '',
                'description' =>
                    __(
                        'You can map basic WooCommerce order data to MiniCRM fields here. Write one per line in the following format:'
                        . "\n<br><code>[WooCommerce data]:[MiniCRM field]</code>"
                        . "\n<br>\n<br>You can use the ones below as <code>[WooCommerce data]</code>:",
                        'minicrm-woocommerce-sync'
                    )
                    . implode (
                        "\n",
                        array_map (
                            function (string $field, string $help) {
                                return "<br><code>$field</code> <small>$help</small>";
                            },
                            array_keys ($validWcFields),
                            $validWcFields
                        )
                    )
                    . '<br>'
                    . sprintf (
                        __(
                            'You can also try other WooCommerce order data at your own risk (look for <code>get...()</code> methods of <a href="%s" target="_blank">WC_Order</a>.).',
                            'minicrm-woocommerce-sync'
                        ),
                        // TODO: Replace WC version dynamically with required/current one.
                        'https://github.com/woocommerce/woocommerce/blob/3.0.9/includes/class-wc-order.php'
                    )
                    . "<br><br>"
                    . sprintf (
                        __(
                            "You can use the text displayed as <em>Field label on HTML forms</em> while editing the custom fields of the <em>Order</em> module for a <code>[MiniCRM field]</code>."
                            . " For example if you see <em>[Project.%1\$s]</em> there, then use only <em>%1\$s</em>."
                            . "\n<br>\n<br>Separate the two fields in a single mapping with <code>:</code> (colon).",
                            'minicrm-woocommerce-sync'
                        ),
                        __ ('PostcodeOfShipping', 'minicrm-woocommerce-sync')
                    ),
                'placeholder' => __(
                    "Example:"
                    . "\nshipping_postcode:PostcodeOfShipping"
                    . "\nshipping_city:CityOfShipping",
                    'minicrm-woocommerce-sync'
                ),
                'title' => __('WooCommerce data mapping', 'minicrm-woocommerce-sync'),
                'type' => 'textarea',
            ],
            'proxy_header' => [
                'default'     => '',
                'desc_tip'    => __(
                    'You have to set this option if your website runs behind a reverse proxy service (eg.: Content Delivery Network like Cloudflare or Cloudfront). This field should have the name of the HTTP header your proxy service uses to send the original visitors IP address.',
                    'proxy_header'
                ),
                'description' => __(
                    'Only fill this and the following field if you are using a proxy service. Some common proxy headers for reference:'
                    . "\n<br>"
                    . implode (
                        ", ",
                        array_map (
                            function (string $header) {
                                return "<code>$header</code>";
                            },
                            Configuration::PROXY_HEADERS
                        )
                    ), 'proxy_header'),
                'title'       => __('Proxy header', 'proxy_header'),
                'type'        => 'text',
            ],
            'proxy_ip_start' => [
                'default'     => '',
                'desc_tip'    => __(
                    'This option must be filled if you use the proxy_header field to get the visitors IP address. To make sure malicious third parties can\'t inject a fake IP address you must check what IP range your proxy service sends requests to your site and insert the lowest IP of that range here.',
                    'proxy_ip_start'
                ),
                'title'       => __('Poxy IP Range Start', 'proxy_ip_start'),
                'type'        => 'text',
            ],
            'proxy_ip_end' => [
                'default'     => '',
                'desc_tip'    => __(
                    'This option must be filled if you use the proxy_header field to get the visitors IP address. Check from what IP range your proxy service sends requests to your site. Insert the highest IP of that range here.',
                    'proxy_ip_end'
                ),
                'title'       => __('Poxy IP Range End', 'proxy_ip_end'),
                'type'        => 'text',
            ]
        ];

        // Add checkbox for Extra Product Options mapping
        if (Plugin::isPluginActive ('woocommerce-tm-extra-product-options/tm-woo-extra-product-options.php')) {
            $this->form_fields ['epo_mapping'] = [
                'css' => 'font-family: monospace;',
                'default' => '',
                'description' =>
                    __(
                        'You can map <em>Extra Product Options</em> data to MiniCRM fields here. Write one mapping per line in the following format:'
                        . "\n<br><code>[Product Option]:[MiniCRM field]</code>"
                        . "\n<br>\n<br>Use the label displayed to the customer as a <code>[Product Option]</code> (the one typed under <em>Label</em> on the <em>Extra Product Options</em> admin)!",
                        'minicrm-woocommerce-sync'
                    )
                    . "<br><br>"
                    . sprintf (
                        __(
                            "You can use the text displayed as <em>Field label on HTML forms</em> while editing the custom fields of the <em>Order</em> module for a <code>[MiniCRM field]</code>."
                            . " For example if you see <em>[Project.%1\$s]</em> there, then use only <em>%1\$s</em>."
                            . "\n<br>\n<br>Separate the two fields in a single mapping with <code>:</code> (colon).",
                            'minicrm-woocommerce-sync'
                        ),
                        __ ('TextOnTshirt', 'minicrm-woocommerce-sync')
                    )
                    . '<br><br>'
                    . __(
                        "<strong>If you change the label of a product option</strong>, it's recommended that you keep the old mapping and add another one with the new label, since previous orders stored the product option with its old label.",
                        'minicrm-woocommerce-sync'
                    ),
                'placeholder' => __(
                    "Example:"
                    . "\nT-shirt text:TextOnTshirt"
                    . "\nExtended warranty:ExtendedWarranty",
                    'minicrm-woocommerce-sync'
                ),
                'title' => __(
                    'Extra Product Options mapping',
                    'minicrm-woocommerce-sync'
                ),
                'type' => 'textarea',
            ];
        }
    }

    // NOTE: Validates field BASED ON METHOD NAME as a built-in feature.
    public function validate_api_key_field ($key, $value)
    {
        if (preg_match ('/^[a-zA-Z0-9]{32}$/', $value) !== 1) {
            \WC_Admin_Settings::add_error (__(
                'The API key is required and should consist of 32 alphanumeric characters. Please type the correct setting.',
                'minicrm-woocommerce-sync'
            ));
            return $this->get_option ($key);
        }
        return $value;
    }

    // NOTE: Validates field BASED ON METHOD NAME as a built-in feature.
    public function validate_category_id_field ($key, $value)
    {
        if (preg_match ('/^\d+$/', $value) !== 1) {
            \WC_Admin_Settings::add_error (__(
                'The category ID is required and should consist of digits only. Please type the correct setting.',
                'minicrm-woocommerce-sync'
            ));
            return $this->get_option ($key);
        }
        return $value;
    }

    public function validate_epo_mapping_field ($key, $value)
    {
        $mapping = self::getMapping ($value);
        $invalid = $this->_invalidXmlNames (array_values ($mapping));
        if (count ($invalid)) {
            \WC_Admin_Settings::add_error (sprintf (
                __(
                    "The following MiniCRM mappings are invalid: %s",
                    'minicrm-woocommerce-sync'
                ),
                implode (', ', $invalid)
            ));
            return $this->get_option ($key);
        }
        return $value;
    }

    // NOTE: Validates field BASED ON METHOD NAME as a built-in feature.
    public function validate_folder_name_field ($key, $value)
    {
        if (empty ($value) !== false) {
            \WC_Admin_Settings::add_error (__(
                'The folder name is required. Please type the correct setting.',
                'minicrm-woocommerce-sync'
            ));
            return $this->get_option ($key);
        }
        return $value;
    }

    // NOTE: Validates field BASED ON METHOD NAME as a built-in feature.
    public function validate_system_id_field ($key, $value)
    {
        if (preg_match ('/^\d+$/', $value) !== 1) {
            \WC_Admin_Settings::add_error (__(
                'The System ID is required and should consist of digits only. Please type the correct setting.',
                'minicrm-woocommerce-sync'
            ));
            return $this->get_option ($key);
        }
        return $value;
    }

    public function validate_wc_mapping_field ($key, $value)
    {
        $mapping = self::getMapping ($value);
        $hasErrors = false;

        // Check WooCommerce order properties
        $invalid = array_filter (array_keys ($mapping), function ($wcProp) {
            $wco_Class = apply_filters( 'woocommerce_order_class', \WC_Order::class, 'order', 0 );
            return !method_exists ($wco_Class, "get_$wcProp");
        });
        if (count ($invalid)) {
            \WC_Admin_Settings::add_error (sprintf (
                __(
                    "The following WooCommerce mappings are invalid: %s",
                    'minicrm-woocommerce-sync'
                ),
                implode (', ', $invalid)
            ));
            $hasErrors = true;
        }

        // Check XML names
        $invalid = $this->_invalidXmlNames (array_values ($mapping));
        if (count ($invalid)) {
            \WC_Admin_Settings::add_error (sprintf (
                __(
                    "The following MiniCRM mappings are invalid: %s",
                    'minicrm-woocommerce-sync'
                ),
                implode (', ', $invalid)
            ));
            $hasErrors = true;
        }

        return $hasErrors ? $this->get_option ($key) : $value;
    }

    protected function _invalidXmlNames (array $xmlNames): array
    {
        return array_filter (
            $xmlNames,
            function ($xmlName) { return !$this->_isValidXmlName ($xmlName); }
        );
    }

    // NOTE: Checking only a basic, reduced set of XML standard, omitting colon.
    protected function _isValidXmlName (string $name): bool
    {
        return preg_match ('/^[A-Z_a-z][A-Z_a-z-.0-9]*$/', $name) === 1;
    }
}
