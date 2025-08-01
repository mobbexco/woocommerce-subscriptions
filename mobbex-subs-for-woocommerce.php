<?php
/**
 * Plugin Name: Mobbex Subscriptions for WooCommerce
 * Description: Plugin that integrates Mobbex Subscriptions in WooCommerce.
 * Version: 3.3.5
 * WC tested up to: 4.2.2
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

/**
 * This file includes code from the "Plugin Update Checker" project.
 * https://github.com/YahnisElsts/plugin-update-checker
 * 
 * Licensed under the MIT License:
 * Copyright (c) 2010–2025 Janis Elsts
 * https://w-shadow.com/
 * 
 * This license notice is retained in accordance with the terms of the MIT License.
 */

require_once 'includes/helper.php';
require_once 'includes/utils.php';
require_once 'includes/logger.php';
require_once !class_exists('Mobbex\Model') ? 'includes/lib/class-api.php' : WP_PLUGIN_DIR . '/woocommerce-mobbex/includes/class-api.php';
require_once !class_exists('Mobbex\Model') ? 'includes/lib/model.php' : WP_PLUGIN_DIR . '/woocommerce-mobbex/includes/model.php';
require_once 'includes/class-subscription.php';
require_once 'includes/class-subscriber.php';

class Mbbx_Subs_Gateway
{
    public static $version = '3.3.5';

    /**
     * @var Mbbxs_Helper
     */
    public static $helper;

    /**
     * Errors Array.
     */
    public static $errors = [];

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
        try {
            Mbbx_Subs_Gateway::check_dependencies();
            Mbbx_Subs_Gateway::load_textdomain();
            Mbbx_Subs_Gateway::load_update_checker();
            Mbbx_Subs_Gateway::check_upgrades();
            Mbbx_Subs_Gateway::load_helper();
            Mbbx_Subs_Gateway::load_subscription_product();
            Mbbx_Subs_Gateway::load_cart();
            //Mbbx_Subs_Gateway::register_scheduled_event();
        } catch (Exception $e) {
            Mbbx_Subs_Gateway::$errors[] = $e->getMessage();
        }

        if (count(Mbbx_Subs_Gateway::$errors)) {
            foreach (Mbbx_Subs_Gateway::$errors as $error) {
                self::$helper::notice('error', $error);
            }

            return;
        }

        Mbbx_Subs_Gateway::load_order_settings();

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        //Product Settings
        require_once plugin_dir_path(__FILE__) . 'includes/admin/product-settings.php';
        
        Mbbx_Subs_Gateway::load_product_settings();

        /*add_filter('cron_schedules', function ($schedules) {
            $schedules['5seconds'] = array(
                'interval' => 5,
                'display' =>'5 segundos'
            );
            return $schedules;
        });
        add_action('mbbxs_cron_event', [$this, 'check_subscriptions_payments']);

        // Desactivation hook
        register_deactivation_hook(__FILE__, [$this, 'plugin_desactivation']);*/
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    private static function check_dependencies()
    {
        if (!class_exists('WooCommerce')) {
            Mbbx_Subs_Gateway::$errors[] = __('WooCommerce needs to be installed and activated.', 'mobbex-subs-for-woocommerce');
        }

        if (!function_exists('WC')) {
            Mbbx_Subs_Gateway::$errors[] = __('Mobbex requires WooCommerce to be activated', 'mobbex-subs-for-woocommerce');
        }

        if (!is_ssl()) {
            Mbbx_Subs_Gateway::$errors[] = __('Your site needs to be served via HTTPS to comunicate securely with Mobbex.', 'mobbex-subs-for-woocommerce');
        }

        if (version_compare(WC_VERSION, '2.6', '<')) {
            Mbbx_Subs_Gateway::$errors[] = __('Mobbex requires WooCommerce version 2.6 or greater', 'mobbex-subs-for-woocommerce');
        }

        if (!function_exists('curl_init')) {
            Mbbx_Subs_Gateway::$errors[] = __('Mobbex requires the cURL PHP extension to be installed on your server', 'mobbex-subs-for-woocommerce');
        }

        if (!function_exists('json_decode')) {
            Mbbx_Subs_Gateway::$errors[] = __('Mobbex requires the JSON PHP extension to be installed on your server', 'mobbex-subs-for-woocommerce');
        }

        $openssl_warning = __('Mobbex requires OpenSSL >= 1.0.1 to be installed on your server', 'mobbex-subs-for-woocommerce');
        if (!defined('OPENSSL_VERSION_TEXT')) {
            Mbbx_Subs_Gateway::$errors[] = $openssl_warning;
        }

        preg_match('/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches);
        if (empty($matches[1])) {
            Mbbx_Subs_Gateway::$errors[] = $openssl_warning;
        }

        if (!version_compare($matches[1], '1.0.1', '>=')) {
            Mbbx_Subs_Gateway::$errors[] = $openssl_warning;
        }
    }

    private static function load_textdomain()
    {
        load_plugin_textdomain('mobbex-subs-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    private static function load_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/helper.php';
        self::$helper = new Mbbxs_Helper;
    }

    private static function load_update_checker()
    {
        require_once 'plugin-update-checker/plugin-update-checker.php';;

        $updater = \YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
            'https://github.com/mobbexco/woocommerce-subscriptions/',
            __FILE__,
            'mobbex-subs-plugin-update-checker'
        );

        // Do not remove this line, it is needed to help IDE and PHPSTAN to recognize the type
        /** @var \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitHubApi $githubApi */
        $githubApi = $updater->getVcsApi();
        $githubApi->enableReleaseAssets();
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_subscription_product()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/subscription-product.php';
    }

    /**
     * Utility functions and hooks for Cart
     */
    private static function load_cart()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/cart.php';
        Mbbxs_Cart::init();
    }

    public static function woocommerce_init()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/gateway.php';

        // Add gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', function ($methods) {
            $methods[] = MOBBEX_SUBS_WC_GATEWAY;
            return $methods;
        });

        // Supports HPOS
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class))
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }

    /**
     * Load admin product settings.
     */
    private static function load_product_settings()
    {
        Mbbx_Subs_Product_Settings::init();
    }

    /**
     * Load admin order settings and panels.
     */
    private static function load_order_settings()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/admin/order-settings.php';
        Mbbx_Subs_Order_Settings::init();
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
                '<a href="' . esc_url(Mbbx_Subs_Gateway::$site_url) . '" target="_blank">' . __('Website', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(Mbbx_Subs_Gateway::$doc_url) . '" target="_blank">' . __('Documentation', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(Mbbx_Subs_Gateway::$github_url) . '" target="_blank">' . __('Contribute', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(Mbbx_Subs_Gateway::$github_issues_url) . '" target="_blank">' . __('Report Issues', 'mobbex-subs-for-woocommerce') . '</a>',
            ];

            $links = array_merge($links, $plugin_links);
        }

        return $links;
    }

    /**
     * Schedule a daily event for subscriptions payments in 'no integrations' mode.
     */
    /*public static function register_scheduled_event()
    {
        // If you are not registered yet
        if(!wp_next_scheduled('mbbxs_cron_event')) {
            wp_schedule_event(current_time('timestamp'), 'daily', 'mbbxs_cron_event');
        }
    }*/

    /**
     * Triggered on plugin deactivation or uninstallation.
     */
    /*public static function plugin_desactivation()
    {
        // Unregister event for subscriptions payments
        wp_clear_scheduled_hook('mbbxs_cron_event');
    }*/

    /*public function check_subscriptions_payments()
    {
        $var = 'example';
    }*/

    /**
     * Check pending database upgrades and upgrade if is needed.
     */
    public static function check_upgrades()
    {
        try {
            if (version_compare(get_option('mobbex-subs-version'), MOBBEX_SUBS_VERSION, '>='))
                return;

            install_mobbex_subs_tables();
        } catch (\Exception $e) {
            self::$errors[] = 'Mobbex DB Upgrade error';
        }
    }
}

function install_mobbex_subs_tables() {
    global $wpdb;
    // Get install query from sql file
    $querys = explode('/', str_replace('PREFIX_', $wpdb->prefix, file_get_contents(WP_PLUGIN_DIR . '/woocommerce-mobbex-subs/setup/install.sql'))); 
    //Execute the querys
    foreach ($querys as $query) {
        $wpdb->get_results($query);
    }

    update_option('mobbex-subs-version', MOBBEX_SUBS_VERSION);
}

$mbbx_subs_gateway = new Mbbx_Subs_Gateway;
add_action('init', [$mbbx_subs_gateway, 'init']);
add_action('before_woocommerce_init', [$mbbx_subs_gateway, 'woocommerce_init']);
register_activation_hook(__FILE__, 'install_mobbex_subs_tables');