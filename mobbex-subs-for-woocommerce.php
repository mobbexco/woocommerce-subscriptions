<?php
/**
 * Plugin Name: Mobbex for WooCommerce Subscriptions
 * Description: A small plugin that provides Woocommerce Subscriptions <-> Mobbex integration.
 * Version: 1.0.1
 * WC tested up to: 4.2.2
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2020 mobbex.com
 */

require_once 'includes/utils.php';

class MobbexSubsGateway
{
    /**
     * Errors Array.
     */
    static $errors = [];

    /**
     * Mobbex URL.
     */
    public static $site_url = "https://www.mobbex.com";

    /**
     * Gateway documentation URL.
     */
    public static $doc_url = "https://mobbex.dev";

    /**
     * Github URLs.
     */
    public static $github_url = "https://github.com/mobbexco/woocommerce-subscriptions";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce-subscriptions/issues";

    public function init()
    {
        MobbexSubsGateway::check_dependencies();
        MobbexSubsGateway::load_textdomain();
        MobbexSubsGateway::load_helper();
        MobbexSubsGateway::load_update_checker();

        if (count(MobbexSubsGateway::$errors)) {

            foreach (MobbexSubsGateway::$errors as $error) {
                MobbexSubsHelper::notice('error', $error);
            }

            return;
        }

        MobbexSubsGateway::load_gateway();
        MobbexSubsGateway::add_gateway();

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    public static function check_dependencies()
    {
        if (!class_exists('WooCommerce')) {
            MobbexSubsGateway::$errors[] = __('WooCommerce needs to be installed and activated.', 'mobbex-subs-for-woocommerce');
        }

        if (!function_exists('WC')) {
            MobbexSubsGateway::$errors[] = __('Mobbex requires WooCommerce to be activated', 'mobbex-subs-for-woocommerce');
        }

        if (!is_ssl()) {
            MobbexSubsGateway::$errors[] = __('Your site needs to be served via HTTPS to comunicate securely with Mobbex.', 'mobbex-subs-for-woocommerce');
        }

        if (version_compare(WC_VERSION, '2.6', '<')) {
            MobbexSubsGateway::$errors[] = __('Mobbex requires WooCommerce version 2.6 or greater', 'mobbex-subs-for-woocommerce');
        }

        if (!function_exists('curl_init')) {
            MobbexSubsGateway::$errors[] = __('Mobbex requires the cURL PHP extension to be installed on your server', 'mobbex-subs-for-woocommerce');
        }

        if (!function_exists('json_decode')) {
            MobbexSubsGateway::$errors[] = __('Mobbex requires the JSON PHP extension to be installed on your server', 'mobbex-subs-for-woocommerce');
        }

        $openssl_warning = __('Mobbex requires OpenSSL >= 1.0.1 to be installed on your server', 'mobbex-subs-for-woocommerce');
        if (!defined('OPENSSL_VERSION_TEXT')) {
            MobbexSubsGateway::$errors[] = $openssl_warning;
        }

        preg_match('/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches);
        if (empty($matches[1])) {
            MobbexSubsGateway::$errors[] = $openssl_warning;
        }

        if (!version_compare($matches[1], '1.0.1', '>=')) {
            MobbexSubsGateway::$errors[] = $openssl_warning;
        }
    }

    public static function load_textdomain()
    {
        load_plugin_textdomain('mobbex-subs-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public static function load_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/helper.php';
    }

    public static function load_update_checker()
    {
        require 'plugin-update-checker/plugin-update-checker.php';
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/mobbexco/woocommerce-subscriptions/',
            __FILE__,
            'mobbex-subs-plugin-update-checker'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
    }

    public static function load_gateway()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/gateway.php';
    }

    public static function add_gateway()
    {
        add_filter('woocommerce_payment_gateways', function ($methods) {

            $methods[] = MOBBEX_SUBS_WC_GATEWAY;
            return $methods;

        });
    }

    public function add_action_links($links)
    {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mobbex_subs') . '">' . __('Settings', 'mobbex-subs-for-woocommerce') . '</a>',
        ];

        $links = array_merge($plugin_links, $links);

        return $links;
    }

    /**
     * Plugin row meta links
     *
     * @access public
     * @param  array $input already defined meta links
     * @param  string $file plugin file path and name being processed
     * @return array $input
     */
    public function plugin_row_meta($links, $file)
    {
        if (strpos($file, plugin_basename(__FILE__)) !== false) {
            $plugin_links = [
                '<a href="' . esc_url(MobbexSubsGateway::$site_url) . '" target="_blank">' . __('Website', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexSubsGateway::$doc_url) . '" target="_blank">' . __('Documentation', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexSubsGateway::$github_url) . '" target="_blank">' . __('Contribute', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexSubsGateway::$github_issues_url) . '" target="_blank">' . __('Report Issues', 'mobbex-subs-for-woocommerce') . '</a>',
            ];

            $links = array_merge($links, $plugin_links);
        }

        return $links;
    }
}

$mobbexSubsGateway = new MobbexSubsGateway;
add_action('plugins_loaded', [ & $mobbexSubsGateway, 'init']);