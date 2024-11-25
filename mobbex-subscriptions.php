<?php
/**
 * Plugin Name: Mobbex Subscriptions for WooCommerce
 * Description: Plugin that integrates Mobbex Subscriptions in WooCommerce.
 * Version: 4.0.0
 * WC tested up to: 6.7.0
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

// Sdk classes
// Only requires autload if the file exists to avoid fatal errors

if (file_exists(__DIR__ . '/vendor/autoload.php'))
    require_once __DIR__ . '/vendor/autoload.php';

require_once plugin_dir_path(__FILE__) . 'utils/definitions.php';

class MobbexSubscriptions
{
    public static $version = '4.0.0';
    public static $id;

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
        add_filter('mobbex_checkout_custom_data', [$this, 'modify_checkout_data'], 10, 2);
        add_filter('mobbex_subs_support', [$this, 'add_subscription_support'], 10, 2);

        // Always Required
        add_action('woocommerce_scheduled_subscription_payment_' . self::$id, [$this, 'scheduled_subscription_payment'], 10, 2);

        // Update subscription status
        add_action('woocommerce_subscription_status_active', [$this, 'update_subscriber_state']);
        add_action('woocommerce_subscription_status_cancelled', [$this, 'update_subscriber_state']);
        
        add_action('woocommerce_api_mobbex_subs_return_url', [$this, 'mobbex_subs_return_url']);
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
    public function modify_checkout_data($checkout)
    {
        if (!$checkout)
            return ['result' => 'error'];

        // TODO foreach items searching another subs
        $subscription = \MobbexSubscription\Cart::get_subscription($checkout['items'][0]['entity']);

        if ($subscription){

            self::$logger->log('debug', 'MobbexSubscriptions > modify_checkout_data | Checkout to modify', $checkout);
            $checkout_helper = new \Mobbex\WP\Checkout\Model\Helper;

            // Modify checkout
            $checkout['total']   -= $subscription->calculate_checkout_total($checkout['total']);
            $checkout['webhook']  = $checkout_helper->get_api_endpoint('mobbex_subs_webhook');
            $checkout['items'][0] = [
                'type'      => 'subscription',
                'reference' => $subscription->uid,
            ];

            // Maybe add sign up fee 
            if ((float) $subscription->signup_fee > 0){
                $checkout['items'][] = [
                    'total'        => (float) $subscription->signup_fee,
                    'description'  => $subscription->name . ' - costo de instalación',
                    'quantity'     => 1,
                ];
            }

            // Remove merchants node
            unset($checkout['merchants']);

            // Make sure to use json in pay for order page
            if (isset($_GET['pay_for_order']))
                wp_send_json($checkout) && exit;

            self::$logger->log('debug', 'MobbexSubscriptions > modify_checkout_data | Modified Checkout', $checkout);
        }
        
        if (!$subscription)
            self::$logger->log(
                'debug', 'MobbexSubscriptions > modify_checkout_data | Subscription is null/not found',
                ['product id' => $checkout['items'][0]['entity']]
            );

        return $checkout;
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
        // Añadir comprobacion para ver si nos corresponder cambiar el estado
        try {
            // Checks that subscription or order id is nor null
            if (!$subscription || !$subscription->get_parent())
                throw new \Exception(__('MobbexSubscription > update_subscriber_state -  error: Subscription or parent order not found on state update', 'mobbex-subs-for-woocommerce'));

            // Gets subscription status, order id
            $status   = $subscription->get_status();
            $order_id = $subscription->get_parent()->get_id();

            // Get susbscriber
            $subscriber = new MobbexSubscription\Subscriber($order_id);

            // Update subscriber state through the corresponding endpoint
            $subscriber->update_status($status);
            
        } catch (\Exception $e) {
            $subscription->add_order_note(__('MobbexSubscription > update_subscriber_state - Error modifying subscriber status: ', 'mobbex-subs-for-woocommerce') . $e->getMessage());
        }
    }
    
    public function mobbex_subs_webhook()
    {
        $token    = $_REQUEST['mobbex_token'];
        $id       = isset($_REQUEST['mobbex_order_id']) ? $_REQUEST['mobbex_order_id'] : null;
        $postData = isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json' ? json_decode(file_get_contents('php://input'), true) : $_POST;

        $this->process_webhook($token, $postData['data'], $postData['type'], $id);

        echo "WebHook OK: Mobbex for WooCommerce Subscriptions v" . MOBBEX_SUBS_VERSION;
        die();
    }

    public function process_webhook($token, $data, $type, $order_id)
    {
        $status = $data['payment']['status']['code'];

        if (empty($status) || empty($token) || !$type || empty($type) || !\Mobbex\Repository::validateToken($token))
            return false;

        //Compatibility with 2.x subscriptions
        if ($order_id) {
            $order = wc_get_order($order_id);

            if (!isset($order))
                $this->logger->log('debug', 'MobbexSubscription > process_webhook - Order cannot be loaded on webhook', ['data' => $data, 'order_id' => $order_id]); // esto probalemente se elimina despues para usar lo de abajo
            // If there is an order, it stores the order subscriptions in the table  
            // if($order)
            //     $this->subs_order_helper->maybe_migrate_subscriptions($order);
            // else
            //  $this->logger->log('debug', 'Order cannot be loaded on webhook', ['data' => $data, 'order_id' => $order_id]);
        }

        $subscription = \MobbexSubscription\Subscription::get_by_uid($data['subscriptions'][0]['subscription']);
        $subscriber   = \MobbexSubscription\Subscriber::get_by_uid($data['subscriptions'][0]['subscriber']);

        if (!isset($subscription, $subscriber)){
            $this->logger->log('debug', 'MobbexSubscription > process_webhook - Subscription or Subscriber cannot be loaded', $data);
            return false;
        }

        $state = \MobbexSubscription\Helper::get_state($status);
        $dates = $subscription->calculateDates();

        // Recognize kind of subscription
        if (\MobbexSubscription\Helper::is_wcs_active() && wcs_order_contains_subscription($order_id)) {
            // Get a WCS subscription if possible
            $subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
            $wcs_sub       = end($subscriptions);
            $this->logger->log('debug', 'MobbexSubscription > process_webhook - is WCS Subscription', ['wcs_sub' => $wcs_sub]);
        } else if (\MobbexSubscription\Cart::has_subscription($order_id)) {
            // If has a mobbex subscription set standalone
            $standalone = true;
        } else {
            // No subscriptions
            return false;
        }

        $this->logger->log('debug', 'MobbexSubscription > process_webhook - type: ' . $type, []);
        // Manage registration or execution
        if ($type === 'checkout'){
            // Avoid duplicate registration process
            if ($subscriber->register_data) {
                $this->logger->log('debug', 'MobbexSubscription > process_webhook - Avoid duplicate registration', ['register_data' => $subscriber->register_data]);
                $order->add_order_note('Avoid attempt to re-register Subscriber UID: ' . $data['subscriber']['uid']);
                return false;
            }
            if (!$subscriber) {
                $subscriber = new \MobbexSubscription\Subscriber(
                    $order_id,
                    $subscription->uid,
                    $data["payment"]["reference"],
                    $data["customer"]["name"],
                    $data["customer"]["email"],
                    $data["customer"]["phone"],
                    $data["customer"]["identification"],
                    $data["customer"]["uid"]
                );
                $result = $subscriber->save();
            }

            if (!$subscription || !$subscriber) {
                $this->logger->log('debug', 'MobbexSubscription > process_webhook - Subscription or subscriber cannot be loaded', ['subscription' => $subscription, 'subscriber' => $subscriber]);
                return false;
            }

            $order->add_order_note('Mobbex Subscription UID: ' . $subscription->uid);
            $order->add_order_note('Mobbex Subscriber UID:' . $subscriber->uid);
            $this->logger->log('debug', 'MobbexSubscription > process_webhook - Mobbex Subscription UID: ' . $subscription->uid . 'Mobbex Subscriber UID: ' . $subscriber->uid, []);

            // Get registration result from context status
            $result = !empty($data['context']['status']) && $data['context']['status'] === 'success';

            // Save registration data and update subscriber state
            $subscriber->register_data = json_encode($data);
            $subscriber->state         = $status;
            $subscriber->start_date    = $dates['current'];

            // Standalone mode
            if (isset($standalone)) {
                if ($result) {
                    $order->payment_complete($order_id);
                } else {
                    $order->update_status('failed', __('MobbexSubscription > process_webhook -  Validation failed', 'mobbex-subs-for-woocommerce'));
                }
            } else if (isset($wcs_sub)) {
                // Enable subscription
                if ($result)
                    $wcs_sub->payment_complete();
            }
        } elseif ($type === 'subscription:execution'){
            $this->logger->log('debug', 'MobbexSubscription > process_webhook - is standalone: ' . isset($standalone), []);
            $this->logger->log('debug', 'MobbexSubscription > process_webhook - execution state: ' . $state, []);
            // If status look fine
            if ($state == 'approved' || $state == 'on-hold') {
                // Mark as payment complete
                if (isset($standalone)) {
                    $order->payment_complete();
                } else if (isset($wcs_sub)) {
                    $wcs_sub->payment_complete();
                }
            } else {
                // Mark as payment failed
                if (isset($standalone)) {
                    $order->update_status('failed', __('MobbexSubscription > process_webhook - Execution failed', 'mobbex-subs-for-woocommerce'));
                } else if (isset($wcs_sub)) {
                    $wcs_sub->payment_failed();
                }
            }
        }
        // Update execution dates
        $subscriber->last_execution = $dates['current'];
        $subscriber->next_execution = $dates['next'];

        //Save the subscriber with updated data
        $subscriber->save(false);

        // Save webhooks data in execution table
        $subscriber->saveExecution($data, $order_id, $subscriber->last_execution);

        return true;
    }
}
