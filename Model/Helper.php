<?php
namespace MobbexSubscription;
class Helper
{
    public static $config;
    public static $logger;

    public static $periods = [
        'd' => 'day',
        'm' => 'month',
        'y' => 'year'
    ];

    public function __construct()
    {
        self::$config = new \Mobbex\WP\Checkout\Model\Config;
        self::$logger = new \Mobbex\WP\Checkout\Model\Logger;
    }

    public static function is_wcs_active()
    {
        return (!empty(self::$config->integration) && self::$config->integration === 'wcs' && get_option('woocommerce_subscriptions_is_active'));
    }

    /**
     * Get the post id for 2.x subs compatibility.
     * 
     * @param string $product_id
     * @param mixed $order
     * 
     * @return string
     */
    public function get_post_id($product_id, $order)
    {
        if ($order && $this->is_wcs_active()) {
            $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
            $wcs_sub = end($subscriptions);
            return (new \MobbexSubscription\Subscription)->is_stored($wcs_sub->order->get_id()) ? $wcs_sub->order->get_id() : $product_id;
        } else {
            return $product_id;
        }
    }

    /**
	 * Get payment state from Mobbex status code.
     * 
     * @param int|string $status
     * 
     * @return string "approved" | "on-hold" | "cancelled"
	 */
    public static function get_state($status)
    {
        if ($status == 2 || $status == 3 || $status == 100 || $status == 201) {
            return 'on-hold';
        } else if ($status == 4 || $status >= 200 && $status < 300) {
            return 'approved';
        } else if ($status >= 300 && $status < 400) {
            return 'processing';
        } else {
            return 'cancelled';
        }
	}

    /**
     * Display sign up fee on product price
     * 
     * @param string $price_html
     * @param WC_Product $product
     * 
     * @return string $sign_up_fee || $price_html
     */
    public static function display_sign_up_fee_on_price($price_html, $product)
    {
        // Sometimes the hook gets an array type product
        $product_id = is_object($product)? $product->get_id() : $product['product_id'];

        // Avoid non subscription products
        if (!\MobbexSubscription\Product::is_subscription($product_id))
            return $price_html;

        // Set sign up price
        $sign_up_price = self::get_product_subscription_signup_fee($product_id);

        return $sign_up_price ? $price_html .= __(" /month and a $$sign_up_price sign-up fee") : $price_html;
    }

    /*
     * Get product subscription sign-up fee from db
     * 
     * @param int|string $id
     * 
     * @return string|null product subscription sign-up fee
     */
    public static function get_product_subscription_signup_fee($id)
    { 
        try {
            $subscription = (new \MobbexSubscription\Subscription)->get_by_id($id);;

            if (!$subscription)
                return null;

            return ((float) $subscription->signup_fee > 0) ? $subscription->signup_fee : null;
        } catch (\Exception $e) {
            self::$logger->log('error', '\MobbexSubscription\Helper > get_product_subscription_signup_fee | Failed obtaining setup fee: ' . $e->getMessage(), isset($subscription));
        }
    }

    /**
     * Get subscription.
     * 
     * @param WC_Order|WC_Abstract_Order $order
     * @param string $return_url
     * @return MobbexSubscription|null $response_data
     */
    public function get_subscription($order)
    {
        $this->logger->log('debug', "Get subscription. Order ID: " . $order->get_id());
        $subscription = new \MobbexSubscription\Subscription;

        $order_id    = $order->get_id();
        $sub_options = [
            'type'     => $this->is_wcs_active() ? 'manual' : 'dynamic',
            'interval' => '',
            'trial'    => '',
            'interval' => '',
        ];

        // Get subscription product name
        foreach ($order->get_items() as $item) {
            $product    = $item->get_product();
            $product_id = $product->get_id();
            $post_id    = $this->helper->get_post_id($product_id, $order);

            //Add basic options
            $sub_options['post_id']   = $post_id; 
            $sub_options['reference'] = "wc_order_{$post_id}";
            $sub_options['price']     = $product->get_price();
            $sub_options['name']      = $product->get_name();
            

            if ($this->helper->is_wcs_active() && WC_Subscriptions_Product::is_subscription($product))
                $sub_options['signup_fee'] = WC_Subscriptions_Product::get_sign_up_fee($product) ?: 0;

            if (\MobbexSubscription\Product::is_subscription($product_id)) {
                    $sub_options['interval']   = implode(\MobbexSubscription\Product::get_charge_interval($product_id));
                    $sub_options['trial']      = \MobbexSubscription\Product::get_free_trial($product_id)['interval'];
                    $sub_options['signup_fee'] = \MobbexSubscription\Product::get_signup_fee($product_id);
            }

            if ($this->helper->is_wcs_active() && !\WC_Subscriptions_Product::is_subscription($product) && !$this->helper->has_subscription($order_id)) {
                $this->logger->log('error', "Subscription not found in product. Order ID: " . $order->get_id(), [$product_id, $this->helper->is_wcs_active(), \WC_Subscriptions_Product::is_subscription($product), $this->helper->has_subscription($order_id)]);

                apply_filters('simple_history_log', __METHOD__ . ": Order #$order_id does not contain any Subscription", null, 'error');
                return;
            }
        }

        $this->logger->log('debug', "Get subscription. Before creation. Order ID: " . $order->get_id(), $sub_options);
        $subscription = $subscription->create_mobbex_subscription($sub_options);
        $this->logger->log('debug', "Get subscription. After creation. Order ID: " . $order->get_id(), !empty($subscription->uid) ? $subscription->uid : null);

        if (!empty($subscription->uid))
            return $subscription;

        return;
    }

    /**
     * Get subscriber.
     * 
     * @param WC_Order|WC_Abstract_Order $order
     * @param integer $mbbx_subscription_uid
     * @return MobbexSubscriber|null $response_data
     */
    public function get_subscriber($order, $mbbx_subscription_uid)
    {
        $this->logger->log('debug', "Get subscriber. Init. Order ID: " . $order->get_id() . ". Subscription UID: $mbbx_subscription_uid");

        $order_id     = $order->get_id();
        $current_user = wp_get_current_user();

        $dni_key   = !empty($this->config->custom_dni) ? $this->config->custom_dni : '_billing_dni';
        $reference = get_post_meta($order_id, $dni_key, true) ? get_post_meta($order_id, $dni_key, true) . $current_user->ID : $current_user->ID;

        $name        = $current_user->display_name ?: $order->get_formatted_billing_full_name();
        $email       = $current_user->user_email ?: $order->get_billing_email();
        $phone       = get_user_meta($current_user->ID, 'phone_number', true) ?: $order->get_billing_phone();
        $dni         = get_post_meta($order_id, $dni_key, true);
        $customer_id = $current_user->ID ?: null;

        $this->logger->log('debug', "Get subscriber. Before creation. Order ID: " . $order->get_id() . ". Subscription UID: $mbbx_subscription_uid", [
            $order_id,
            $mbbx_subscription_uid,
            $reference,
            $name,
            $email,
            $phone,
            $dni,
            $customer_id
        ]);

        // Create subscriber
        $subscriber = new \MobbexSubscription\Subscriber(
            $order_id,
            $mbbx_subscription_uid,
            $reference,
            $name,
            $email,
            $phone,
            $dni,
            $customer_id
        );

        $this->logger->log('debug', "Get subscriber. After creation. Order ID: " . $order->get_id() . '. Subscription UID: ' .  $mbbx_subscription_uid);

        // Save subscriber and sync with mobbex
        $result = $subscriber->save();

        $this->logger->log('debug', "Get subscriber. After save. Order ID: " . $order->get_id() . '. Subscription UID: ' .  $mbbx_subscription_uid, [$result, $subscriber]);

        if ($result)
            return $subscriber;

        return null;
    }
}