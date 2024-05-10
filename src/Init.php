<?php
namespace Mobbex\WP\Subscriptions;

class Init
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
     * @var Mobbex\WP\Checkout\Model\Logger
     */
    public static $logger;

    /**
     * Github URLs.
     */
    public static $github_url = "https://github.com/mobbexco/woocommerce-subscriptions";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce-subscriptions/issues";

    public function init()
    {
        try {
            Init::load_helper();
            Init::load_subscription_product();
            Init::load_cart();
            Init::load_subs_gateway();
            //Init::register_scheduled_event();
        } catch (\Exception $e) {
            Init::$errors[] = $e->getMessage();
        }
        
        if (count(Init::$errors)) {
            foreach (Init::$errors as $error)
                self::$logger->notice($error, 'error', 'Mobbex Subscriptions');
            return;
        }
        Init::load_order_settings();
        Init::load_product_settings();
    }

    private static function load_helper()
    {
        require_once MOBBEX_SUBS_DIRECTORY . 'Model/Helper.php';
        self::$helper = new \Mobbex\WP\Subscriptions\Model\Helper;
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_subscription_product()
    {
        require_once MOBBEX_SUBS_DIRECTORY . 'Model/SubscriptionProduct.php';
    }

    /**
     * Utility functions and hooks for Cart
     */
    private static function load_cart()
    {
        require_once MOBBEX_SUBS_DIRECTORY . 'Model/Cart.php';
        \Mobbex\WP\Subscriptions\Model\Cart::init();
    }

    
    /**
     * Load admin product settings.
     */
    private static function load_product_settings()
    {
        require_once MOBBEX_SUBS_DIRECTORY . 'admin/product-settings.php';
        \Mobbex\WP\Subscriptions\Admin\ProductSettings::init();
    }
    
    /**
     * Load admin order settings and panels.
     */
    private static function load_order_settings()
    {
        require_once MOBBEX_SUBS_DIRECTORY . 'admin/order-settings.php';
        \Mobbex\WP\Subscriptions\Admin\OrderSettings::init();
    }
    
    public static function get_config_options()
    {
        return include(MOBBEX_SUBS_DIRECTORY . 'admin/config-options.php');
    }

    public static function load_subs_gateway()
    {
        require_once MOBBEX_SUBS_DIRECTORY . 'Gateway.php';
        new \Mobbex\WP\Subscriptions\Gateway;
    }
}