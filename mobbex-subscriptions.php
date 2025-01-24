<?php
/**
 * Plugin Name: Mobbex for Woocommerce Subscription 
 * Description: Extension that integrates Mobbex Subscriptions in Mobbex For Woocommerce.
 * Version: 4.0.0
 * WC tested up to: 6.7.0
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

// Only requires autload if the file exists to avoid fatal errors
if (file_exists(__DIR__ . '/vendor/autoload.php'))
require_once __DIR__ . '/vendor/autoload.php';

require_once plugin_dir_path(__FILE__) . 'utils/definitions.php';

class MobbexSubscriptions
{
    public static $version = '4.0.0';

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public static $logger;

    /** @var \Mobbex\WP\Checkout\Model\Config */
    public static $config;

    /**
     * @var \MobbexSubscription\Helper
     */
    public static $helper;

    /**
     * @var \Mobbex\WP\Checkout\Helper\Order
     */
    public static $order_helper;

    /**
     * @var \MobbexSubscription\OrderHelper
     */
    public static $subs_order_helper;

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
            MobbexSubscriptions::load_logger();
            MobbexSubscriptions::load_config();
            MobbexSubscriptions::load_helper();
            MobbexSubscriptions::load_textdomain();
            MobbexSubscriptions::load_subs_order_helper();
            MobbexSubscriptions::load_model();
            MobbexSubscriptions::load_subscription_product();
            MobbexSubscriptions::load_subscription();
            MobbexSubscriptions::load_subscriber();
            MobbexSubscriptions::load_order_settings();
            MobbexSubscriptions::load_cart();
            MobbexSubscriptions::load_product_settings();
        } catch (Exception $e) {
            MobbexSubscriptions::$errors[] = $e->getMessage();
        }
        
        if (count(MobbexSubscriptions::$errors)) {
            foreach (MobbexSubscriptions::$errors as $error) {
                self::$logger->notice('error', $error);
                self::$logger->log('debug', 'Mobbex Subscriptions Init Error', $error);
            }
            
            return;
        }
        // Always
        add_filter('mobbex_subs_scheduled_payment', [$this, 'scheduled_subscription_payment'], 10, 2);
        add_filter('mobbex_checkout_custom_data', [$this, 'modify_checkout_data'], 10, 2);
        add_filter('mobbex_subs_support', [$this, 'add_subscription_support'], 10, 2);

        // Update subscription status
        add_action('woocommerce_subscription_status_active', [$this, 'update_subscriber_state']);
        add_action('woocommerce_subscription_status_cancelled', [$this, 'update_subscriber_state']);
        
        add_action('woocommerce_api_mobbex_subs_return_url', [$this, 'mobbex_return_url']);
        add_action('woocommerce_api_mobbex_subs_webhook', [$this, 'mobbex_subs_webhook']);

    }

    private static function load_textdomain()
    {
        load_plugin_textdomain('mobbex-subs-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    private static function load_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Helper.php';
        self::$helper = new \MobbexSubscription\Helper;
    }

    private static function load_subs_order_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/OrderHelper.php';
        self::$subs_order_helper = new \MobbexSubscription\OrderHelper;
    }

    private static function load_logger()
    {
        self::$logger = new \Mobbex\WP\Checkout\Model\Logger;
    }

    private static function load_config()
    {
        self::$config = new \Mobbex\WP\Checkout\Model\Config;
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_model()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Model.php';
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_subscription_product()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Product.php';
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_subscription()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Subscription.php';
    }

    /**
     * Utility functions for Subscription Product
     */
    private static function load_subscriber()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Subscriber.php';
    }

    /**
     * Utility functions and hooks for Cart
     */
    private static function load_cart()
    {
        require_once plugin_dir_path(__FILE__) . 'Model/Cart.php';
        \MobbexSubscription\Cart::init();
    }

    /**
     * Load admin product settings.
     */
    private static function load_product_settings()
    {
        require_once plugin_dir_path(__FILE__) . 'admin/product-settings.php';
        \MobbexSubscription\ProductSettings::init();
    }

    /**
     * Load admin order settings and panels.
     */
    private static function load_order_settings()
    {
        require_once plugin_dir_path(__FILE__) . 'admin/order-settings.php';
        \MobbexSubscription\Order_Settings::init();
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
                '<a href="' . esc_url(MobbexSubscriptions::$site_url) . '" target="_blank">' . __('Website', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexSubscriptions::$doc_url) . '" target="_blank">' . __('Documentation', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexSubscriptions::$github_url) . '" target="_blank">' . __('Contribute', 'mobbex-subs-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexSubscriptions::$github_issues_url) . '" target="_blank">' . __('Report Issues', 'mobbex-subs-for-woocommerce') . '</a>',
            ];

            $links = array_merge($links, $plugin_links);
        }

        return $links;
    }

    /**
     * Add subscriptions supports to checkout
     * 
     * @param array $support checkout supports
     * 
     * @return array filteres supports
     */
    public static function add_subscription_support($supports)
    {
        return array_merge($supports, [
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change_customer',
            ]
        );
    }

    /**
	 * Checks if page is pay for order and change subs payment page.
	 */
    public static function is_subs_change_method()
    {
		return (isset($_GET['pay_for_order']) && isset($_GET['change_payment_method']));
	}

    /**
     * Modify checkout data to add subscription
     * 
     * @param string $checkout
     * @return array
     */
    public function modify_checkout_data($checkout, $id)
    {
        if (!$checkout)
            return ['result' => 'error'];

        $logger = new \Mobbex\WP\Checkout\Model\Logger;

        // TODO foreach items searching another subs
        $subscription = \MobbexSubscription\Cart::get_subscription($checkout['items'][0]['entity']);
        
        if (!$subscription || self::$config->enable_subscription != "yes"){
            !$subscription ?? $logger->log(
                'debug', 
                'MobbexSubscriptions > modify_checkout_data | Subscription not found or extension is desactivated',
                ['subscription' => $subscription, 'enable_subscription' => self::$config->enable_subscription]);
            return $checkout;
        }

        $logger->log('debug', 'MobbexSubscriptions > modify_checkout_data | Checkout to modify', $checkout);
        $checkout_helper = new \Mobbex\WP\Checkout\Model\Helper;

        // Remove merchants node and items
        unset($checkout['merchants'], $checkout['items']);

        // Modify checkout
        $checkout['total']      -= $subscription->calculate_checkout_total($checkout['total']);
        $checkout['webhook']     = $checkout_helper->get_api_endpoint('mobbex_subs_webhook', $id);
        $checkout['return_url']  = $checkout_helper->get_api_endpoint('mobbex_return_url', $id);
        $checkout['items'][]  = [
            'type'      => 'subscription',
            'reference' => $subscription->uid,
        ];

        // Maybe add sign up fee 
        if ($subscription->type != 'manual' && (float) $subscription->signup_fee > 0)
            $checkout['items'][] = [
                'total'        => (float) $subscription->signup_fee,
                'description'  => $subscription->name . ' - costo de instalación',
                'quantity'     => 1,
            ];

        $logger->log('debug', 'MobbexSubscriptions > modify_checkout_data | Modified Checkout', $checkout);
        return $checkout;
    }
    
    /**
     * Process webhook when the user returns from the payment gateway.
     */
    public function mobbex_subs_webhook()
    {
        $token    = $_REQUEST['mobbex_token'];
        $id       = isset($_REQUEST['mobbex_order_id']) ? $_REQUEST['mobbex_order_id'] : null;
        $postData = isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json' ? json_decode(file_get_contents('php://input'), true) : $_POST;
        $logger   = new \Mobbex\WP\Checkout\Model\Logger;

        // Order webhook filter
        $webhookData = \Mobbex\WP\Checkout\Model\Helper::format_webhook_data($id, $postData['data']);
        $logger->log(
            'debug', 
            'MobbexSubscription > mobbex_subs_webhook - WebhookData', 
            $webhookData
        );

        // Save transaction
        global $wpdb;
        $wpdb->replace(
            "{$wpdb->prefix}mobbex_transaction", 
            $webhookData, 
            \Mobbex\WP\Checkout\Model\Helper::db_column_format($webhookData)
        );
        
        $this->process_webhook($token, $postData['data'], $postData['type'], $id, $logger);

        echo "WebHook OK: Mobbex for WooCommerce Subscriptions v" . MOBBEX_SUBS_VERSION;
        $logger->log(
            "debug", 
            "MobbexSubscription > mobbex_subs_webhook - WebHook OK: Mobbex for WooCommerce Subscriptions v" . MOBBEX_SUBS_VERSION, 
            []
        );
        
        die();
    }

    public function process_webhook($token, $data, $type, $order_id, $logger)
    {
        $status = $data['payment']['status']['code'];
        $order  = wc_get_order($order_id);

        if (empty($status) || empty($token) || !$type || empty($type) || !\Mobbex\Repository::validateToken($token))
            return false;

        // Get subscription and subscriber
        $subscriptions = $this->format_subs_webhook($type, $data);
        $subscription  = (new \MobbexSubscription\Subscription)->get_by_uid(
            $subscriptions['subscription_uid'],
            );
        $subscriber    = (new \MobbexSubscription\Subscriber)->get(
            $subscriptions['subscriber_uid'],
            $subscription->uid,
            $order_id
            );

        if (!isset($subscription, $subscriber)){
            $logger->log(
                'debug', 
                'MobbexSubscription > process_webhook - Subscription or Subscriber cannot be loaded', 
                $data
            );
            return false;
        }
        $logger->log(
            "debug", 
            "MobbexSubscription > process_webhook - Mobbex Subscription UID: $subscription->uid 'Mobbex Subscriber UID: $subscriber->uid"
        );

        $state = \MobbexSubscription\Helper::get_state($status);
        $dates = $subscription->calculate_dates();

        // Recognize kind of subscription. Get a WCS subscription if possible, otherwise set standalone or return false
        if (\MobbexSubscription\Helper::is_wcs_active() && wcs_order_contains_subscription($order_id)) {
            $wcs_subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
            $wcs_sub           = end($wcs_subscriptions);
            $logger->log('debug', 'MobbexSubscription > process_webhook - is WCS Subscription', ['wcs_sub' => $wcs_sub]);
        } else if ($subscription->type == 'dynamic') {
            $logger->log('debug', 'MobbexSubscription > process_webhook - is standalone');
            $standalone = true;
        } else {
            $logger->log('debug', "MobbexSubscription > process_webhook - No subscription found for order $order_id");
            return false;
        }

        // Manage registration or execution
        if ($type === 'checkout'){
            // Avoid duplicate registration process
            if ($subscriber->register_data) {
                $logger->log(
                    'debug', 
                    'MobbexSubscription > process_webhook - Avoid duplicate registration', 
                    ['register_data' => $subscriber->register_data]
                );
                $order->add_order_note("Avoid attempt to re-register Subscriber UID: $subscriber->uid");
                return false;
            }

            $logger->log('debug', "MobbexSubscription > process_webhook | type: $type | status: $status");
            // Save registration data and update subscriber state
            $subscriber->register_data = json_encode($data);
            $subscriber->state         = $status;
            $subscriber->start_date    = $dates['current'];

            // Get registration result from context status
            $result = $status == 200;
            $logger->log('debug', 'MobbexSubscription > process_webhook - Registration result: ' . $result, []);
            $order->add_order_note(
                "Mobbex Subscription/Subcriber Registration UID: {$subscriptions['subscription_uid']} / {$subscriptions['subscriber_uid']}"
            );

            if (isset($wcs_sub) && $result)
                $wcs_sub->payment_complete(); // Enable subscription
            elseif (isset($standalone) && $result)
                $order->payment_complete($order_id);
            else
                $order->update_status('failed', __('MobbexSubscription > process_webhook -  Validation failed', 'mobbex-subs-for-woocommerce'));

        } elseif ($type === 'subscription:execution'){
            // Avoid duplicate execution process
            if ($subscriber->get_execution($data['execution']['uid'])) {
                $logger->log(
                    'debug', 
                    "MobbexSubscription > process_webhook - Avoid duplicate manual execution webhook | ex-uid: {$data['execution']['uid']}", 
                    ['execution' => $data['execution']]
                );
                $order->add_order_note("Avoid attempt to manual re-execute | Subscriber UID: $subscriber->uid");
                return false;
            }

            $logger->log('debug', "MobbexSubscription > process_webhook | type: $type | status: $status");
            $order->add_order_note(
                "Mobbex Subscription/Subcriber Manual Execution | UID: {$subscriptions['subscription_uid']} / {$subscriptions['subscriber_uid']}"
            );
            
            if ($state == 'approved' || $state == 'on-hold') {
                // Mark as payment complete
                if (isset($standalone))
                    $order->payment_complete();
                else if (isset($wcs_sub)){
                    $renewal_order = wcs_create_renewal_order($wcs_sub);

                    try {
                        $renewal_order->set_total($subscriptions['execution']['total']);
                        $renewal_order->save();
                        $renewal_order->payment_complete($data['payment_id']);
                        $wcs_sub->payment_complete();
                    } catch (\Exception $e) {
                        $logger->log(
                            'debug', 
                            "MobbexSubscription > process_webhook - Error creating renewal order: {$e->getMessage()}", 
                            ['error' => $e]
                        );
                    }
                }

                $order->add_order_note("MobbexSubscription > process_webhook - Manual Execution : success");
            } else {
                // Mark as payment failed
                if (isset($standalone))
                    $order->update_status('failed', __('MobbexSubscription > process_webhook - Manual Execution : failed', 'mobbex-subs-for-woocommerce'));
                else if (isset($wcs_sub))
                    $wcs_sub->payment_failed();

                $order->add_order_note("MobbexSubscription > process_webhook - Manual Execution : failed. No subscription found for order $order_id");   
            }
        }
        // Update execution dates
        $subscriber->last_execution = $dates['current'];
        $subscriber->next_execution = $dates['next'];

        //Save the subscriber with updated data
        $subscriber->save(false);

        // Save webhooks data in execution table
        $subscriber->save_execution($data, $subscriber->last_execution);

        return true;
    }

    /**
     * Format the webhook data to get the subscription and subscriber
     * 
     * @param string $type webhook type
     * @param array $data webhook data
     * 
     * @return array formated webhook data
     */
    public function format_subs_webhook($type, $data){
        $format_data = [];

        if ($type === 'checkout')
            $format_data = [
                'subscription_uid' => $data['subscriptions'][0]['subscription'],
                'subscriber_uid'   => $data['subscriptions'][0]['subscriber'],
            ];
        else
            $format_data = [
                'subscription_uid' => $data['subscription']['uid'],
                'subscriber_uid'   => $data['subscriber']['uid'],
                'execution'        => $data['execution'],
            ];

        return $format_data;
    }

    /**
     * Executed by WooCommerce Subscriptions in each billing period.
     * 
     * @param integer $renewal_total
     * @param WC_Order|WC_Abstract_Order $renewal_order
     * 
     * @return bool Result of charge execution.
     */
    public function scheduled_subscription_payment($renewal_total, $renewal_order)
    {
        $logger = new \Mobbex\WP\Checkout\Model\Logger;

        // Return if payment method is not Mobbex
        if (MOBBEX_WC_GATEWAY_ID != $renewal_order->get_payment_method()){
            $logger->log(
                "debug", 
                "MobbexSubscription > scheduled_subscription_payment - Payment method is not Mobbex.", 
                ['payment_method' => $renewal_order->get_payment_method()]
            );
            return;
        }

        $logger->log(
            "debug", 
            "MobbexSubscription > scheduled_subscription_payment - Init for $$renewal_total - Order ID {$renewal_order->get_id()}"
        );
        $renewal_order->add_order_note("MobbexSubscription > scheduled_subscription_payment - Processing scheduled payment for $ $renewal_total");

        // Get subscription from order id
        $subscriptions = wcs_get_subscriptions_for_order($renewal_order->get_id(), ['order_type' => 'any']); 
        $wcs_sub       = end($subscriptions);
        $logger->log(
            "debug", 
            "MobbexSubscription > scheduled_subscription_payment - Getting subscriptions. Order ID {$renewal_order->get_id()}", 
            [$wcs_sub, $wcs_sub->get_id(), $wcs_sub->order ? $wcs_sub->order->get_id() : null]
        );
        
        // Get Mobbex subscriber
        $subscriber = \MobbexSubscription\Subscriber::get_by_id($wcs_sub->get_parent_id(), false);

        if(!$subscriber){
            $renewal_order->add_order_note("Error executing subscription. Subscriber not found. Parent Order ID: {$wcs_sub->get_parent_id()}");
            return false;
        }
        $logger->log(
            'debug', 
            "MobbexSubscription > scheduled_subscription_payment - Subscriber $subscriber->uid obtained succesfuly. Order ID {$renewal_order->get_id()}", 
            $subscriber->uid
        );

        try {
            $renewal_order->add_order_note("Executing charge for Mobbex Subscription $subscriber->subscription_uid and Mobbex Subscriber $subscriber->uid");
            $subscriber->execute_charge(
                implode('_', [$subscriber->subscription_uid, $subscriber->uid, $renewal_order->get_id()]),
                $renewal_total
            );
            $logger->log(
                'debug',
                "MobbexSubscription > scheduled_subscription_payment - Execute Charge Result $subscriber->subscription_uid $subscriber->uid. Order ID {$renewal_order->get_id()}",
                ['return' => true]
            );

            $renewal_order->payment_complete();
            $renewal_order->add_order_note("MobbexSubscription > scheduled_subscription_payment - Charge executed successfully");

            return true;
        } catch (\Exception $e) {
            $wcs_sub->payment_failed();
            $logger->log(
                "debug", 
                "MobbexSubscription > scheduled_subscription_payment - Charge execution error: {$e->getMessage()}, Order ID {$renewal_order->get_id()}",
                ['return' => false]
            );

            $renewal_order->update_status('failed','MobbexSubscription > scheduled_subscription_payment - Charge execution failed', 'mobbex-subs-for-woocommerce');
            $renewal_order->add_order_note("MobbexSubscription > scheduled_subscription_payment - Charge execution error: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Send the corresponding endpoint to the Mobbex API to update the subscription status
     * 
     * Called when the subscription status is changed.
     * 
     * @param WC_Subscription $subscription
     */
    public function update_subscriber_state($subscription)
    {
        try {
            // Checks that subscription or order id is nor null
            if (!$subscription || !$subscription->get_parent())
                throw new \Exception(__(
                    'MobbexSubscription > update_subscriber_state -  error: Subscription or parent order not found on state update', 
                    'mobbex-subs-for-woocommerce'
                ));

            // Gets subscription status, order id
            $status   = $subscription->get_status();
            $order_id = $subscription->get_parent()->get_id();

            // Get susbscriber
            $subscriber = new MobbexSubscription\Subscriber($order_id);

            // Update subscriber state through the corresponding endpoint
            $subscriber->update_status($status);
            
        } catch (\Exception $e) {
            $subscription->add_order_note(__(
                'MobbexSubscription > update_subscriber_state - Error modifying subscriber status', 
                'mobbex-subs-for-woocommerce') . $e->getMessage()
            );
        }
    }
}