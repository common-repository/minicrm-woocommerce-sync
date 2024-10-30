<?php

namespace MiniCRM\WoocommercePlugin;

// Prevent direct access
if (!defined ('ABSPATH')) {
    exit;
}

abstract class AbstractXmlEndpoint
{
    /** @throws \Exception on error */
    abstract protected static function _buildXml (): \SimpleXMLElement;

    /** @return void */
    public static function display ()
    {
        try {
            // Authorize by request IP address
            self::_validateIp ();
            self::_verifySecret ();

            // Remove possible theme content off the beginning
            while (ob_get_level ()) {
                ob_end_clean ();
            }

            $xml = static::_buildXml ();
            header ('Content-Type: application/xml');
            echo $xml->asXML ();
            exit;
        }
        catch (\Exception $e) {
            http_response_code (400);
            header ('Content-Type: text/plain');
            exit ($e->getMessage ());
        }
    }

    /**
     * @return void
     * @throws \Exception if IP is not allowed
     */
    protected static function _validateIp ()
    {
        $Address = $_SERVER ['REMOTE_ADDR'];
        $proxyHeader = Integration::getOption ("proxy_header");

        if ($proxyHeader != '') {
            self::_isIpInRange ($_SERVER [$proxyHeader], Integration::getOption ("proxy_ip_start"), Integration::getOption ("proxy_ip_end"));
            return;
        }

        if (!in_array ($Address, Configuration::VALID_IPS)) {
            throw new \Exception ("$Address IP is not allowed.", 401);
        }
    }

    /**
     * This function validates if an ip is inside a given ip range. (Only works for ipv4)
     * @param string ip address
     * @param string starting point of the ip range
     * @param string end point of the ip range
     * @return void
     * @throws \Exception if any IPs are invalid or they are not in the given range
     * */
    protected static function _isIpInRange ($ip, $rangeLow, $rangeHigh)
    {
        $ip = ip2long ($ip);
        $rangeLow = ip2long ($rangeLow);
        $rangeHigh = ip2long ($rangeHigh);

        if ($ip === false || $rangeLow === false  || $rangeHigh === false) throw new \Exception ('Invalid IP in range or request was made from an invalid IP.', 401);

        if ($rangeLow <= $ip && $ip <= $rangeHigh) return;

        throw new \Exception ('Proxy ip was not in range.', 401);
    }

    /**
     * This function verifies the nonce given in the request
     * @return void
     * @throws \Exception if secret is invalid or expired
     */
    protected static function _verifySecret ()
    {
        if (!get_transient ($_GET['secret'])) throw new \Exception ('Failed to validate secret.', 401);
    }
}
