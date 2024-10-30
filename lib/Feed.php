<?php

namespace MiniCRM\WoocommercePlugin;

// Prevent direct access
if (!defined ('ABSPATH')) {
    exit;
}

class Feed extends AbstractXmlEndpoint
{
    /**
     * @var int A product ID is required in the MiniCRM SyncFeed for each order
     *          item. In case of fees & deleted products, we use the unique item
     *          ID plus this offset.
     */
    const RESERVED_PRODUCT_ID_START = 10000000;

    /**
     * @var int When syncing multiple shops into a single MiniCRM account, we
     *          avoid ID collisions with a unique offset for each shop. Each
     *          WooCommerce shop can be set up with a multiplier of 0 to 99
     *          (kind of a shop ID), and the final ID will be incremented with
     *          this offset size multiplied by the shop ID.
     */
    const SHOP_OFFSET_SIZE = 100000000;

    /**
     * @var int MiniCRM gives up downloading the feed after this amount of time.
     *          The shop should also wait the response of a sync request for (at
     *          least) this amount of time, because MiniCRM only responds once
     *          finished downloading the feed (although processing the feed
     *          happens asynchronously).
     */
    const TIMEOUT_IN_SECS = 120;

    /** @return void */
    protected static function _addCustomWcFields (
        \SimpleXMLElement $orderProject,
        \WC_Order $order
    ) {
        $textValue = Integration::getOption ('wc_mapping');
        $mapping = Integration::getMapping ($textValue);
        foreach ($mapping as $wcField => $mcField) {
            $method = "get_$wcField";
            $value = $order->$method ();
            $orderProject->{$mcField} = $value;
        }
    }

    /** @return void */
    protected static function _addExtraProductOptions (
        \SimpleXMLElement $orderProject,
        \WC_Order $order
    )
    {
        // Check if "Extra Product Options" plugin is active
        if (!Plugin::isPluginActive ('woocommerce-tm-extra-product-options/tm-woo-extra-product-options.php')) {
            return;
        }

        // Get mapping
        $textValue = Integration::getOption ('epo_mapping');
        $mapping = Integration::getMapping ($textValue);

        // Collect items' options
        $itemsOptions = [];
        foreach ($order->get_items () as $item) {
            $options = $item->get_meta ('_tmcartepo_data');
            if (!is_array ($options)) {
                continue;
            }
            foreach ($options as $option) {
                if (
                    !isset ($option ['name'], $option ['value'])
                    || !is_string ($option ['name'])
                    || !is_string ($option ['value'])
                ) {
                    continue;
                }
                $field = $mapping [$option ['name']] ?? null;
                if (is_null ($field)) {
                    continue;
                }
                $itemId = $item->get_id ();
                $itemName = $item->get_name ();
                $itemQty  = $item->get_quantity ();
                $itemsOptions [$field] [] = [
                    'item' => "$itemQty x $itemName (#$itemId)",
                    'option' => $option ['value'],
                ];
            }
        }

        // Write values to XML
        foreach ($itemsOptions as $field => $itemOptions) {
            $orderProject->{$field} = implode ("\n", array_map (
                function ($itemOption) {
                    return "$itemOption[item]: $itemOption[option]";
                },
                $itemOptions
            ));
        }
    }

    /** @throws \Exception if any error occurs. */
    protected static function _buildXml (): \SimpleXMLElement
    {
        // Get customer id
        $query = $_GET [Plugin::FEED_QUERY_VAR] ?? '';
        $isValidQuery = preg_match (
            '/^(all|\d+(,\d+)*)\.xml$/',
            $query,
            $queryMatches
        );
        if ($isValidQuery !== 1) {
            throw new \Exception ("Invalid query: '$query'.");
        }
        $requestedProjectIds = explode (',', $queryMatches [1]);

        // Get shop ID
        $shopId = (int) Integration::getOption ('shop_id');

        // Get category id
        $category_id = Integration::getOption ('category_id');
        if (empty ($category_id)) {
            throw new \Exception ('Missing option "category_id"');
        }

        // Get folder name
        $folder_name = Integration::getOption ('folder_name');
        if (empty ($folder_name)) {
            throw new \Exception ('Missing option "folder_name"');
        }

        // Sync product descriptions?
        $sync_product_desc = Integration::getOption ('sync_product_desc') === 'yes';

        // Get locale
        $locale = Integration::getOption ('locale');
        if (empty ($locale)) {
            throw new \Exception ('Missing option "locale"');
        }

        // Init local unit
        $localUnit = Configuration::UNIT_MAP [$locale] ?? null;
        if (is_null ($localUnit)) {
            throw new \Exception ("Unexpected locale '$locale'");
        }

        // Get all orders
        $order_filters = [
            'limit'   => -1,
            'orderby' => 'modified',
            'order'   => 'DESC',
            'status'  => Plugin::getAllOrderStatuses (),
        ];
        if ($requestedProjectIds === ['all']) {
            $wc_orders = wc_get_orders ($order_filters);
        }

        // Query orders of requested projects
        else {
            $wc_orders = [];
            foreach ($requestedProjectIds as $requestedProjectId) {
                $requestedProjectId = (int) $requestedProjectId;

                // Get orders of single customer
                if ($requestedProjectId < Configuration::GUEST_OFFSET) {
                    $order_filters ['customer'] = $requestedProjectId;
                    $orders = array_values (wc_get_orders ($order_filters));
                    $wc_orders = array_merge ($wc_orders, $orders);
                }

                // Get single order
                else {
                    $requested_order_id = $requestedProjectId;
                    $requested_order_id -= Configuration::GUEST_OFFSET;
                    $wc_order = wc_get_order ($requested_order_id);
                    if ($wc_order) {
                        $wc_orders [] = $wc_order;
                    }
                }
            }
        }

        // Group orders by customers
        $customers = [];
        foreach ($wc_orders as $wc_order) {
            $project_id = Plugin::getOrderProjectId (
                $wc_order->get_id (),
                $wc_order->get_customer_id ()
            );
            $customers [$project_id] [] = $wc_order;
        }

        // Build XML
        $init_xml_source = '<?xml version="1.0" encoding="utf-8"?><Projects/>';
        $projects = new \SimpleXMLElement ($init_xml_source);
        $timezone = new \DateTimeZone ('Europe/Budapest');
        foreach ($customers as $customer_id => $customer_orders) {

            // No orders for customer
            if (!count ($customer_orders)) {
                continue;
            }

            // Init xml
            $latest_order = $customer_orders [0];
            $business_name = self::_getBillingName ($locale, $latest_order);
            $project = $projects->addChild ('Project');
            $project->addAttribute ('Id', self::_withShopOffset (
                $customer_id,
                $shopId
            ));
            $project->Name = $business_name;
            $project->CategoryId = $category_id;
            switch (count ($customer_orders)) {
                case 0:  $status_key = 'registered'; break;
                case 1:  $status_key = 'new';        break;
                default: $status_key = 'promising';  break;
            }
            $status_id = Configuration::PROJECT_STATUS_MAP [$locale] [$status_key] ?? null;
            if (is_null ($status_id)) {
                throw new \Exception ("Unexpected status '$status_key' in locale '$locale'");
            }
            $project->StatusId = $status_id;

            // Business (optional)
            $company = $latest_order->get_billing_company ();
            if ($company) {
                $business = $project->addChild ('Business');

                // Name
                $business->Name = $company;

                // Vat number
                if (Plugin::isPluginActive ('surbma-magyar-woocommerce/surbma-magyar-woocommerce.php')) {
                    $business->VatNumber = $latest_order->get_meta ('_billing_tax_number');
                }

                // Address
                $addresses = $business->addChild ('Addresses');
                $address = $addresses->addChild ('Address');

                // Try to use billing country
                if ($latest_order->get_billing_country () !== '') {
                    try {
                        $address->CountryId = self::_getCountry (
                            $locale,
                            $latest_order->get_billing_country ()
                        );
                    } catch (\Exception $e) {}
                }

                // Fall back to the shop's base country
                else {
                    try {
                        $address->CountryId = self::_getCountry (
                            $locale,
                            \WC ()->countries->get_base_country ()
                        );
                    } catch (\Exception $e) {}
                }

                $address->PostalCode = $latest_order->get_billing_postcode ();
                $address->City = $latest_order->get_billing_city ();
                $address->addChild (
                    'Address',
                    trim (implode (' ', [
                        $latest_order->get_billing_address_1 (),
                        $latest_order->get_billing_address_2 (),
                    ]))
                );

                // Email
                $emails = $business->addChild ('Emails');
                $email = $emails->addChild ('Email');
                $email->Value = $latest_order->get_billing_email ();

                // Phone
                $phones = $business->addChild ('Phones');
                $phone = $phones->addChild ('Phone');
                $phone->Value = $latest_order->get_billing_phone ();
            }

            // Contact name, email and phone
            $contacts = $project->addChild ('Contacts');
            $contact = $contacts->addChild ('Contact');
            $contact->FirstName = $latest_order->get_billing_first_name ();
            $contact->LastName = $latest_order->get_billing_last_name ();
            $emails = $contact->addChild ('Emails');
            $email = $emails->addChild ('Email');
            $email->Value = $latest_order->get_billing_email ();
            $phones = $contact->addChild ('Phones');
            $phone = $phones->addChild ('Phone');
            $phone->Value = $latest_order->get_billing_phone ();

            // Orders
            $orders = $project->addChild ('Orders');
            foreach ($customer_orders as $order_obj) {

                // Init Order
                $wcStatus = $order_obj->get_status ();
                $mcStatus = Configuration::ORDER_STATUS_MAP [$wcStatus] ?? 'Issued';
                $order = $orders->addChild ('Order');
                $order->addAttribute ('Id', self::_withShopOffset (
                    $order_obj->get_id (),
                    $shopId
                ));
                $order->Number = $order_obj->get_id ();
                $order->CurrencyCode = $order_obj->get_currency ();
                $order->Language = "_{$locale}";
                $completed = $order_obj->get_date_created ();
                $completed->setTimezone ($timezone);
                $order->Performance = $completed->date ('Y-m-d H:i:s');
                $order->addChild ('Status', $mcStatus);

                // Payment method
                $wcPaymentMethod = $order_obj->get_payment_method ();
                $order->PaymentMethod = Configuration::PAYMENT_METHOD_MAP [$wcPaymentMethod] ?? '';

                // Customer
                $customer = $order->addChild ('Customer');

                // Add shipping method
                $shipping_method_name = $order_obj->get_shipping_method ();
                $order_project = $order->addChild ('Project');
                $order_project->ShippingMethod = $shipping_method_name;

                // Add custom WC fields
                self::_addCustomWcFields ($order_project, $order_obj);

                // Add custom data summary of "Extra Product Options" plugin
                self::_addExtraProductOptions ($order_project, $order_obj);

                $name = self::_getCustomerName ($locale, $order_obj);
                $customer->Name = $name;

                // Init empty customer data
                $customer_country = '';
                $customer_postalcode = '';
                $customer_city = '';
                $customer_address = '';

                // Use billing address for Customer if available
                if ($order_obj->get_billing_city () !== '') {

                    // Try to use billing country
                    if ($order_obj->get_billing_country () !== '') {
                        try {
                            $customer_country = self::_getCountry (
                                $locale,
                                $order_obj->get_billing_country ()
                            );
                        } catch (\Exception $e) {}
                    }

                    // Fall back to the shop's base country
                    else {
                        try {
                            $customer_country = self::_getCountry (
                                $locale,
                                \WC()->countries->get_base_country()
                            );
                        } catch (\Exception $e) {}
                    }

                    // Set other address fields only if country has been set
                    if ($customer_country !== '') {
                        $customer_postalcode = $order_obj->get_billing_postcode ();
                        $customer_city = $order_obj->get_billing_city ();
                        $customer_address = implode (' ', [
                            $order_obj->get_billing_address_1 (),
                            $order_obj->get_billing_address_2 (),
                        ]);
                    }
                }

                // Use shipping address otherwise
                elseif ($order_obj->get_shipping_city () !== '') {

                    // Try to use shipping country
                    if ($order_obj->get_shipping_country () !== '') {
                        try {
                            $customer_country = self::_getCountry (
                                $locale,
                                $order_obj->get_shipping_country ()
                            );
                        } catch (\Exception $e) {}
                    }

                    // Fall back to the shop's base country
                    else {
                        try {
                            $customer_country = self::_getCountry (
                                $locale,
                                \WC()->countries->get_base_country()
                            );
                        } catch (\Exception $e) {}
                    }

                    // Set other address fields only if country has been set
                    if ($customer_country !== '') {
                        $customer_postalcode = $order_obj->get_shipping_postcode ();
                        $customer_city = $order_obj->get_shipping_city ();
                        $customer_address = implode (' ', [
                            $order_obj->get_shipping_address_1 (),
                            $order_obj->get_shipping_address_2 (),
                        ]);
                    }
                }

                // Add Customer elements
                $customer->CountryId = $customer_country;
                $customer->PostalCode = $customer_postalcode;
                $customer->City = $customer_city;
                $customer->Address = trim ($customer_address);

                // Products
                $products = $order->addChild ('Products');
                $items = $order_obj->get_items ([
                    'line_item',
                    'shipping',
                    'fee',
                    'coupon',
                ]);
                foreach ($items as $item) {
                    $itemClass = get_class ($item);

                    // Get tax percent
                    try {
                        $taxPercent = self::_getPercentByTaxClass (
                            $order_obj,
                            $item
                        );
                    } catch (\Exception $e) {
                        $msg = $e->getMessage ();
                        $msg .= " (Order #" . $order_obj->get_id () . ", ";
                        switch ($itemClass) {

                            case \WC_Order_Item_Coupon::class:
                                $msg .= "Coupon item #" . $item->get_id ();
                                break;

                            case \WC_Order_Item_Fee::class:
                                $msg .= "Fee item #" . $item->get_id ();
                                break;

                            case \WC_Order_Item_Product::class:
                                $msg .= "Product #" . $item->get_product_id ();
                                break;

                            case \WC_Order_Item_Shipping::class:
                                $msg .= "Shipping fee item #" . $item->get_id ();
                                break;

                            default:
                                $msg .= "Unexpected item class: '$itemClass'";
                        }
                        $msg .= ")";
                        throw new \Exception ($msg);
                    }
                    $product_id = self::_getProductNodeId ($item);

                    // Increment existing Product node qty instead of adding new
                    if ($itemClass === \WC_Order_Item_Product::class) {
                        $existing_products = $products->xpath (
                            "Product[@Id=\"$product_id\"]"
                        );
                        if (count ($existing_products)) {
                            $product = $existing_products [0];
                            $product->Quantity += $item->get_quantity ();
                            continue;
                        }
                    }

                    // Init SKU & Description (will be non-empty for product items only)
                    $sku = '';
                    $productDescription = '';

                    // Get item unit price
                    switch ($itemClass) {

                        case \WC_Order_Item_Coupon::class:
                            $itemName = sprintf (
                                '%s: "%s"',
                                __('Coupon', 'woocommerce'),
                                $item->get_code ()
                            );
                            $netUnitPrice = -$item->get_discount ();
                            break;

                        case \WC_Order_Item_Fee::class:
                            $itemName = $item->get_name ();
                            $netUnitPrice = $item->get_total ();
                            break;

                        case \WC_Order_Item_Product::class:
                            $itemName = $item->get_name ();
                            $netUnitPrice = $item->get_subtotal ();
                            $itemProduct = $item->get_product ();
                            if ($itemProduct instanceof \WC_Product) {
                                $sku = $itemProduct->get_sku ();
                                if ($sync_product_desc) {
                                    $productDescription = $itemProduct->get_description ();
                                    $productDescription = substr ($productDescription, 0, 1024);
                                }
                            }
                            break;

                        case \WC_Order_Item_Shipping::class:
                            $itemName = sprintf (
                                '%s: %s',
                                __('Shipping', 'woocommerce'),
                                $item->get_method_title ()
                            );
                            $netUnitPrice = $item->get_total ();
                            break;

                        default:
                            throw new \Exception ("Unexpected item class: '$itemClass'");
                    }
                    // Apply htmlspecialchars and then safely truncate to 1024 characters
                    $encodedDescription = htmlspecialchars($productDescription, ENT_QUOTES, 'UTF-8');
                    $safeTruncatedDescription = mb_substr($encodedDescription, 0, 1024, 'UTF-8');

                    // Add XML node
                    $product = $products->addChild ('Product');
                    $product->addAttribute ('Id', self::_withShopOffset (
                        $product_id,
                        $shopId
                    ));
                    $product->Name = $itemName;
                    $product->PriceNet = $netUnitPrice / $item->get_quantity ();
                    $product->Quantity = $item->get_quantity ();
                    $product->SKU = $sku;
                    $product->Description = $safeTruncatedDescription;
                    $product->Unit = $localUnit;
                    $product->VAT = "$taxPercent%";
                    $product->FolderName = $folder_name;
                }
            }
        }

        return $projects;
    }

    // NOTE: It's unused, but we save it for later use in tests.
    /**
     * @return void
     * @throws \Exception if grand total calculated from XML node is corrupted
     */
    protected static function _checkOrderNodeIntegrity (
        \WC_Order $wcOrder,
        \SimpleXMLElement $orderNode
    )
    {
        $precision = wc_get_price_decimals ();
        $orderId = $wcOrder->get_id ();

        // Get WooCommerce order grand total
        $wcTotal = $wcOrder->get_total ();
        if (!is_numeric ($wcTotal)) {
            throw new \Exception ("WooCommerce order's total is non-numeric: '$wcTotal'.");
        }

        // Calculate XML node's grand total
        $nodeTotal = 0;
        foreach ($orderNode->Products->Product as $item) {

            // Init VAT multiplier
            $vatMatch = preg_match ('/^(\d+)%$/', $item->VAT, $vatMatches);
            if ($vatMatch !== 1) {
                $productNodeAttributes = $orderNode->attributes ();
                $productNodeId = $productNodeAttributes->Id;
                throw new \Exception ("Invalid VAT '$item->VAT' during integrity check. (Order #$orderId, Product #$productNodeId)");
            }
            $vatMultiplier = 1 + $vatMatches [1] / 100;

            // Calculate subtotal
            $netSubtotal = $item->Quantity * $item->PriceNet;

            // Round net subtotal (WC rounds them individually)
            $netSubtotal = round ($netSubtotal, $precision);

            // Round gross subtotal (WC rounds tax amounts individually)
            $nodeTotal += round ($netSubtotal * $vatMultiplier, $precision);
        }

        // Round calculated node total
        $nodeTotal = round ($nodeTotal, $precision);

        // Compare the two
        if ((float) $nodeTotal !== (float) $wcTotal) {
            throw new \Exception ("Order #$orderId grand total differs: $wcTotal (WC) != $nodeTotal for (XML).");
        }
    }

    /**
     * Creates name string from WooCommerce order's billing data array
     *
     * @param string $locale MiniCRM locale code
     * @param array $order WooCommerce order data
     */
    protected static function _getBillingName (
        string $locale,
        \WC_Order $order
    ): string
    {

        // Get billing or shipping person name
        $customer_name = self::_getCustomerName ($locale, $order);

        // Prepend company if available
        $company = $order->get_billing_company ();
        if (!$company) {
            $company = $order->get_shipping_company ();
        }
        if ($company) {

            // Company + customer name
            if ($customer_name) {
                return "$company ($customer_name)";
            }

            // Company only
            return $company;
        }

        // Customer name only
        return $customer_name;
    }

    /**
     * @param string $locale Locale set in the plugin (eg. "EN")
     * @param string $country_code 2-letter ISO country code given by WC
     *
     * @throws \Exception when confronted with unexpected country code
     */
    protected static function _getCountry (
        string $locale,
        string $countryCode
    ): string
    {
        $name = Configuration::COUNTRIES [$locale] [$countryCode] ?? null;
        if (is_null ($name)) {
            throw new \Exception ("Unexpected country_code '$countryCode' in locale '$locale'");
        }
        return $name;
    }

    /**
     * Creates name string from WooCommerce order data array
     *
     * @param string $locale MiniCRM locale code
     * @param array $order WooCommerce order data
     */
    protected static function _getCustomerName (
        string $locale,
        \WC_Order $order
    ): string
    {
        // Use billing name by default
        $first_name = $order->get_billing_first_name ();
        $last_name  = $order->get_billing_last_name ();

        // Try shipping name if empty
        if ($first_name === '') {
            $first_name = $order->get_shipping_first_name ();
            $last_name  = $order->get_shipping_last_name ();
        }

        return self::_getPersonName ($locale, $first_name, $last_name);
    }

    /** @throws \Exception if fails to select tax percent */
    protected static function _getPercentByTaxClass (
        \WC_Order $order,
        \WC_Order_Item $item
    ): float
    {
        // Query applicable tax rates, item total & item tax amount
        $taxLocation = self::_getOrderTaxLocation ($order);
        $taxParams = ['tax_class' => $item->get_tax_class ()] + $taxLocation;
        $itemClass = get_class ($item);
        switch ($itemClass) {

            case \WC_Order_Item_Coupon::class:
                $rates    = \WC_Tax::find_rates ($taxParams);
                $total    = $item->get_discount ();
                $totalTax = $item->get_discount_tax ();
                break;

            case \WC_Order_Item_Fee::class:
                $rates    = \WC_Tax::find_rates ($taxParams);
                $total    = $item->get_total ();
                $totalTax = $item->get_total_tax ();
                break;

            case \WC_Order_Item_Product::class:
                // NOTE: subtotal(_tax) excludes coupon discont
                // NOTE:    total(_tax) includes coupon discont
                $rates    = \WC_Tax::find_rates ($taxParams);
                $total    = $item->get_subtotal ();
                $totalTax = $item->get_subtotal_tax ();
                break;

            case \WC_Order_Item_Shipping::class:
                $rates    = \WC_Tax::find_shipping_rates ($taxParams);
                $total    = $item->get_total ();
                $totalTax = $item->get_total_tax ();
                break;

            default:
                throw new \Exception ("Unexpected item class '$itemClass'");
        }
        if (!is_numeric ($total)) {
            throw new \Exception ("An item of class '$itemClass' has a non-numeric total with a value of: " . var_export ($total, true));
        }
        if (!is_numeric ($totalTax)) {
            throw new \Exception ("An item of class '$itemClass' has a non-numeric totalTax, with a value of: " . var_export ($totalTax, true));
        }

        // Sum percents
        $percent = 0.0;
        foreach ($rates as $rate) {
            if (!is_numeric ($rate ['rate'])) {
                throw new \Exception ("Non-float tax rate: '$rate[rate]'.");
            }
            $percent += $rate ['rate'];
        }

        /**
         * Fall back to imperfect tax/subtotal calculation if it differs from
         * current tax percent setting. Historical tax percents aren't stored,
         * so this might be used when tax rates change after the order was
         * created (or taxes were recalculated).
         */
        $precision = wc_get_price_decimals ();
        if (!is_int ($precision)) {
            throw new \Exception ("wc_get_price_decimals() returned a value of: " . var_export ($precision, true));
        }
        $totalTaxCalculated = round ($total * $percent / 100, $precision);
        if ((float) $totalTax !== (float) $totalTaxCalculated) {
            $percent = round (100 * $totalTax / $total);
        }

        return (float) $percent;
    }

    /**
     * Formats full person name from WooCommerce object data
     *
     * @param string $locale MiniCRM locale code
     * @param array $data WooCommerce data, eg. order billing data
     *
     * @return string
     */
    protected static function _getPersonName (
        string $locale,
        string $first_name,
        string $last_name
    ): string
    {

        // Eastern name order
        if ($locale === 'HU') {
            $name = "$last_name $first_name";
        }

        // Western name order
        else {
            $name = "$first_name $last_name";
        }

        // Glue together then trim
        return trim ($name);
    }

    /**
     * @return int Original product ID or a transformed one for special cases
     * @throws \Exception on unexpected events.
     */
    protected static function _getProductNodeId (\WC_Order_Item $item): int
    {
        $itemClass = get_class ($item);
        switch ($itemClass) {

            case \WC_Order_Item_Product::class:
                $id = $item->get_product_id ();
                if ($id >= self::RESERVED_PRODUCT_ID_START) {
                    throw new \Exception ("Product ID '$id' is in reserved range.");
                }
                // NOTE: Deleted products don't have product ID
                if (empty ($id)) {
                    return self::RESERVED_PRODUCT_ID_START + $item->get_id ();
                }
                return $id;

            case \WC_Order_Item_Coupon::class:
            case \WC_Order_Item_Fee::class:
            case \WC_Order_Item_Shipping::class:
                return self::RESERVED_PRODUCT_ID_START + $item->get_id ();

            default:
                throw new \Exception ("Unexpected order item class: '$itemClass'.");
        }
    }

    /** Stolen from protected \WC_Abstract_Order::get_tax_location() method */
    protected static function _getOrderTaxLocation (\WC_Order $o): array
    {
        // Is tax based on shipping or billing details?
        $base = get_option ('woocommerce_tax_based_on');
        if ('shipping' === $base && ! $o->get_shipping_country ()) {
            $base = 'billing';
        }

        $location = [
            'country'  => 'billing' === $base ? $o->get_billing_country ()  : $o->get_shipping_country (),
            'state'    => 'billing' === $base ? $o->get_billing_state ()    : $o->get_shipping_state (),
            'postcode' => 'billing' === $base ? $o->get_billing_postcode () : $o->get_shipping_postcode (),
            'city'     => 'billing' === $base ? $o->get_billing_city ()     : $o->get_shipping_city (),
        ];

        // Default to base (?)
        if ('base' === $base || empty ($location ['country'])) {
            $location ['country']  = WC ()->countries->get_base_country ();
            $location ['state']    = WC ()->countries->get_base_state ();
            $location ['postcode'] = WC ()->countries->get_base_postcode ();
            $location ['city']     = WC ()->countries->get_base_city ();
        }

        return $location;
    }

    protected static function _withShopOffset (int $id, int $shopId)
    {
        if ($id > self::SHOP_OFFSET_SIZE) {
            throw new \Exception ("ID #$id exceeds SHOP_OFFSET_SIZE, posing a threat of ID collision.");
        }
        return $id + $shopId * self::SHOP_OFFSET_SIZE;
    }
}
