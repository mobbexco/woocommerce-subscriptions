<?php
namespace Mobbex\WP\Subscriptions;

require_once 'includes/utils.php';

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

    // /**
    //  * Mobbex URL.
    //  */
    // public static $site_url = "https://www.mobbex.com";

    // /**
    //  * Gateway documentation URL.
    //  */
    // public static $doc_url = "https://mobbex.dev";

    /**
     * Github URLs.
     */
    public static $github_url = "https://github.com/mobbexco/woocommerce-subscriptions";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce-subscriptions/issues";

    public function init()
    {
        try {
            Gateway::load_update_checker();
            Gateway::load_helper();
            Gateway::load_subscription_product();
            Gateway::load_cart();
            //Gateway::register_scheduled_event();
        } catch (\Exception $e) {
            Gateway::$errors[] = $e->getMessage();
        }
        
        if (count(Gateway::$errors)) {
            foreach (Gateway::$errors as $error) {
                self::$helper::notice('error', $error);
            }

            return;
        }

        // Always
        // DEBERIAN USAR EL GATEWAY DE CHECKOUT
        // Gateway::load_gateway();
        // Gateway::add_gateway();
        Gateway::load_order_settings();

        // add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
        // add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        //Product Settings
        require_once plugin_dir_path(__FILE__) . 'includes/admin/product-settings.php';
        
        Gateway::load_product_settings();

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

    private static function load_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Helper.php';
        self::$helper = new \Mobbex\WP\Subscriptions\Model\Helper;
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
        require_once plugin_dir_path(__FILE__) . 'Model/SubscriptionProduct.php';
    }

    /**
     * Utility functions and hooks for Cart
     */
    private static function load_cart()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Cart.php';
        Cart::init();
    }

    
    /**
     * Load admin product settings.
     */
    private static function load_product_settings()
    {
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
    // public function add_action_links($links)
    // {
        //     $plugin_links = [
            //         '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mobbex_subs') . '">' . __('Settings', 'mobbex-subs-for-woocommerce') . '</a>',
            //     ];

    //     $links = array_merge($plugin_links, $links);
    
    //     return $links;
    // }
    
    // private static function load_gateway()
    // {
    //     require_once plugin_dir_path(__FILE__) . 'gateway.php';
    // }

    // private static function add_gateway()
    // {
    //     add_filter('woocommerce_payment_gateways', function ($methods) {
    //         $methods[] = MOBBEX_SUBS_WC_GATEWAY;
    //         return $methods;
    //     });
    // }

    /**
     * Plugin row meta links
     *
     * @access public
     * @param  array $input already defined meta links
     * @param  string $file plugin file path and name being processed
     * @return array $input
     */
    // public function plugin_row_meta($links, $file)
    // {
        //     if (strpos($file, plugin_basename(__FILE__)) !== false) {
            //         $plugin_links = [
                //             '<a href="' . esc_url(Gateway::$site_url) . '" target="_blank">' . __('Website', 'mobbex-subs-for-woocommerce') . '</a>',
                //             '<a href="' . esc_url(Gateway::$doc_url) . '" target="_blank">' . __('Documentation', 'mobbex-subs-for-woocommerce') . '</a>',
                //             '<a href="' . esc_url(Gateway::$github_url) . '" target="_blank">' . __('Contribute', 'mobbex-subs-for-woocommerce') . '</a>',
                //             '<a href="' . esc_url(Gateway::$github_issues_url) . '" target="_blank">' . __('Report Issues', 'mobbex-subs-for-woocommerce') . '</a>',
                //         ];
                
                //         $links = array_merge($links, $plugin_links);
    //     }

    //     return $links;
    // }

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

}

function install_mobbex_subs_tables()
{
        //Load Mobbex models in SDK
        \Mobbex\Platform::loadModels(
            new \Mobbex\WP\Checkout\Model\Cache(),
            new \Mobbex\WP\Checkout\Model\Db()
        );

        foreach (['subscription', 'subscriber', 'execution'] as  $tableName) {
            // Create the table or alter table if it exists
            $table = new \Mobbex\Model\Table($tableName);
            // If table creation fails, return false
            if (!$table->result)
                return false;
        }
        
        return true;
}

$Gateway = new Gateway;
add_action('plugins_loaded', [ & $Gateway, 'init']);
register_activation_hook(__FILE__, 'install_mobbex_subs_tables');