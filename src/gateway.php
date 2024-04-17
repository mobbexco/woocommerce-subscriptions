<?php
namespace Mobbex\WP\Subscriptions;

class Gateway
{
    public static $version = '3.1.1';

    /**
     * @var Helper
     */
    public static $helper;

    /**
     * Errors Array.
     */
    public static $errors = [];

    /**
     * @var Mobbex\WP\Checkout\Model\Config
     */
    public static $config;

    /**
     * Github URLs.
     */
    public static $github_url = "https://github.com/mobbexco/woocommerce-subscriptions";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce-subscriptions/issues";

    public function init()
    {
        try {
            Gateway::load_helper();
            Gateway::load_subscription_product();
            Gateway::load_cart();
            //Gateway::register_scheduled_event();
        } catch (\Exception $e) {
            Gateway::$errors[] = $e->getMessage();
        }
        
        if (count(Gateway::$errors)) {
            foreach (Gateway::$errors as $error)
                self::$helper::notice('error', $error);
            return;
        }
        Gateway::load_order_settings();
        Gateway::load_product_settings();

        // HOOKS PARA ENGANCHARSE AL CHECKOUT
        // Add subscription data to checkout
        add_filter('mobbex_cart_subscription_checkout', [$this, 'modify_checkout_data'], 10, 2);
        // Save split data from Mobbex response
        // add_action('mobbex_checkout_process', [$this, 'save_mobbex_response'], 10, 2);

        // //Filter Mobbex Webhook
        // add_filter('mobbex_order_webhook', [$this, 'mobbex_webhook'], 10, 1);
    }

    private static function load_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Helper.php';
        self::$helper = new \Mobbex\WP\Subscriptions\Model\Helper;
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_subscription_product()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/SubscriptionProduct.php';
    }

    /**
     * Utility functions and hooks for Cart
     */
    private static function load_cart()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Cart.php';
        \Mobbex\WP\Subscriptions\Model\Cart::init();
    }

    
    /**
     * Load admin product settings.
     */
    private static function load_product_settings()
    {
        require_once plugin_dir_path(__FILE__) . 'admin/product-settings.php';
        \Mobbex\WP\Subscriptions\Admin\ProductSettings::init();
    }
    
    /**
     * Load admin order settings and panels.
     */
    private static function load_order_settings()
    {
        require_once plugin_dir_path(__FILE__) . 'admin/order-settings.php';
        \Mobbex\WP\Subscriptions\Admin\OrderSettings::init();
    }
    
    public static function get_config_options()
    {
        return include(plugin_dir_path(__FILE__) . 'admin/config-options.php');
    }

    /**
     * Modify checkout data to add subscription functionality.
     * @param array $checkout_data
     */
    public function modify_checkout_data($checkout_data, $order_id)
    {
        
    }
}