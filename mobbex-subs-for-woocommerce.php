<?php
/**
 * Plugin Name: Mobbex Subscriptions for WooCommerce
 * Description: Plugin that integrates Mobbex Subscriptions in WooCommerce.
 * Version: 3.1.3
 * WC tested up to: 4.2.2
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

require_once 'includes/utils.php';
require_once 'includes/logger.php';
require_once !class_exists('Mobbex\Model') ? 'includes/lib/class-api.php' : WP_PLUGIN_DIR . '/woocommerce-mobbex/includes/class-api.php';
require_once !class_exists('Mobbex\Model') ? 'includes/lib/model.php' : WP_PLUGIN_DIR . '/woocommerce-mobbex/includes/model.php';
require_once 'includes/class-subscription.php';
require_once 'includes/class-subscriber.php';

class Mbbx_Subs_Gateway
{
    public static $version = '3.1.3';

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

        // Always
        Mbbx_Subs_Gateway::load_gateway();
        Mbbx_Subs_Gateway::add_gateway();
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
        require 'plugin-update-checker/plugin-update-checker.php';
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/mobbexco/woocommerce-subscriptions/',
            __FILE__,
            'mobbex-subs-plugin-update-checker'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
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

    private static function load_gateway()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/gateway.php';
    }

    private static function add_gateway()
    {
        add_filter('woocommerce_payment_gateways', function ($methods) {
            $methods[] = MOBBEX_SUBS_WC_GATEWAY;
            return $methods;
        });
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
            // Check current version updated
            if (get_option('woocommerce-mobbex-subs-version') < MOBBEX_SUBS_VERSION)
                return;
            
            // Apply upgrades
            install_mobbex_subs_tables();
            
            // Update db version
            update_option('woocommerce-mobbex-subs-version', MOBBEX_SUBS_VERSION);
            
        } catch (\Exception $e) {
            self::$errors[] = 'Mobbex DB Upgrade error';
        }
    }
}

function install_mobbex_subs_tables()
{
    global $wpdb;
    // Get install query from sql file
    $querys = explode('/', str_replace('PREFIX_', $wpdb->prefix, file_get_contents(WP_PLUGIN_DIR . '/woocommerce-mobbex-subs/setup/install.sql'))); 
    //Execute the querys
    foreach ($querys as $query) {
        $wpdb->get_results($query);
    }
}

$mbbx_subs_gateway = new Mbbx_Subs_Gateway;
add_action('plugins_loaded', [ & $mbbx_subs_gateway, 'init']);
register_activation_hook(__FILE__, 'install_mobbex_subs_tables');