<?php
require_once 'utils.php';

class Mbbxs_Helper
{
    public static $periods = [
        'd' => 'day',
        'm' => 'month',
        'y' => 'year'
    ];

    public function __construct()
    {
        // Init settings (Full List in WC_Gateway_Mobbex_Subs::init_form_fields)
        $option_key = 'woocommerce_' . MOBBEX_SUBS_WC_GATEWAY_ID . '_settings';
        $settings = get_option($option_key, null) ?: [];
        foreach ($settings as $key => $value) {
            $key = str_replace('-', '_', $key);
            $this->$key = $value;
        }
    }

    public static function notice($type, $msg)
    {
        add_action('admin_notices', function () use ($type, $msg) {
            $class = esc_attr("notice notice-$type");
            $msg = esc_html($msg);

            ob_start();

            ?>

            <div class="<?=$class?>">
                <h2>Mobbex for Woocommerce Subscriptions</h2>
                <p><?=$msg?></p>
            </div>

            <?php

            echo ob_get_clean();
        });
    }

    public static function _redirect_to_cart_with_error($error_msg)
    {
        wc_add_notice($error_msg, 'error');
        wp_redirect(wc_get_cart_url());

        return array('result' => 'error', 'redirect' => wc_get_cart_url());
    }

    public static function display_mobbex_button($checkout)
    {
        ?>
        <!-- Mobbex Button -->
        <div id="mbbx-button"></div>
        <?php
    }
    
    public function generate_token()
    {
        return md5($this->api_key . '|' . $this->access_token);
    }

    public function valid_mobbex_token($token)
    {
        return $token == $this->generate_token();
    }

    public function get_api_endpoint($endpoint, $order_id)
    {
        $query = [
            'mobbex_token' => $this->generate_token(),
            'platform' => "woocommerce",
            "version" => MOBBEX_SUBS_VERSION,
        ];

        if ($order_id) {
            $query['mobbex_order_id'] = $order_id;
        }

        $query['wc-api'] = $endpoint;

        return add_query_arg($query, home_url('/'));
    }

    public function is_ready()
    {
        return (!empty($this->enabled) && !empty($this->api_key) && !empty($this->access_token) && $this->enabled === 'yes');
    }

    public function is_wcs_active()
    {
        return (!empty($this->integration) && $this->integration === 'wcs' && get_option('woocommerce_subscriptions_is_active'));
    }

    /**
     * Check if Order has a Mobbex Subscription product or a WCS Subscription.
     *
     * @param integer $order_id
     * 
     * @return bool
     */
    public function has_any_subscription($order_id)
    {
        return $this->has_subscription($order_id) || $this->is_wcs_active() && (wcs_is_subscription($order_id) || wcs_order_contains_subscription($order_id));
    }

    /**
	 * Check if Order has a Mobbex Subscription product.
	 *
	 * @param integer $order_id
     * 
     * @return bool
	 */
    public function has_subscription($order_id)
    {
        // Search subscription products in Order
        $order = wc_get_order($order_id);

		foreach ($order->get_items() as $item) {
            $product_id = $item->get_product()->get_id();

            if (Mbbx_Subs_Product::is_subscription($product_id))
                return true;
        }

        return false;
	}

    /**
	 * Check if the current Cart has a Mobbex Subscription product.
	 *
     * @return bool
	 */
    public static function cart_has_subscription()
    {
        $cart_items = WC()->cart ? WC()->cart->get_cart() : [];

        foreach ($cart_items as $item_key => $item) {
            if (Mbbx_Subs_Product::is_subscription($item['product_id']))
                return true;
        }

        return false;
	}

    /**
     * Check if the current Order has a Mobbex Subscription product.
     *
     * @return bool
     */
    public static function order_has_subscription()
    {
        if (empty($_GET['pay_for_order']) || empty(get_query_var('order-pay')))
            return false;

        $order = wc_get_order(get_query_var('order-pay'));

        foreach ($order->get_items() as $item) {
            if (Mbbx_Subs_Product::is_subscription($item->get_product()->get_id()))
                return true;
        }

        return false;
    }

    /**
     * Check if current cart (or pending order) has a wcs subscription.
     * 
     * @return bool|null Null if wcs is inactive.
     */
    public function cart_has_wcs_subscription()
    {
        if (!$this->is_wcs_active())
            return;

        // Try to get pending order (for manual renewals)
        $pending_order = wc_get_order(get_query_var('order-pay'));

        return \WC_Subscriptions_Cart::cart_contains_subscription()
            || wcs_cart_contains_renewal()
            || ($pending_order && wcs_order_contains_subscription($pending_order));
    }

    /**
	 * Checks if page is pay for order and change subs payment page.
	 */
    public static function is_subs_change_method()
    {
		return (isset($_GET['pay_for_order']) && isset($_GET['change_payment_method']));
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
        } else if ($status == 4 || $status >= 200 && $status < 400) {
            return 'approved';
        } else {
            return 'cancelled';
        }
	}

    /**
     * Remove items from cart by type.
     * 
     * @param string $type 'any' | 'subs'
     */
    public static function remove_cart_items($type = 'any')
    {
        $cart_items = !empty(WC()->cart->get_cart()) ? WC()->cart->get_cart() : [];

        foreach ($cart_items as $item_key => $item) {
            if ($type == 'any') {
                WC()->cart->set_quantity($item_key , 0);
            } else if ($type == 'subs' && Mbbx_Subs_Product::is_subscription($item['product_id'])) {
                WC()->cart->set_quantity($item_key , 0);
            }
        }
    }

    /**
     * Execute subscription charge manually using Mobbex API.
     * 
     * @param string $mbbx_subscription_uid
     * @param string $mbbx_subscriber_uid
     * @param integer $total
     * @return array|null $response_result
     */
    public function execute_charge($mbbx_subscription_uid, $mbbx_subscriber_uid, $total)
    {
        $url = str_replace(['{id}', '{sid}'], [$mbbx_subscription_uid, $mbbx_subscriber_uid], MOBBEX_SUBSCRIPTION);
        $body = [
            'total' => $total,
            'test' => $this->test_mode == 'yes',
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body' => json_encode($body),
            'data_format' => 'body',
        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result']))
                return $response['result'];
        }

        return;
    }

    /**
     * Retry Subscription execution using Mobbex API.
     * 
     * @param string|int $order_id
     * @param string|int $execution_id
     * 
     * @return bool $result
     */
    public function retry_execution($order_id, $execution_id)
    {
        if (!$this->is_ready()) {
            throw new Exception(__('Plugin is not ready', 'mobbex-subs-for-woocommerce'));
        }

        // Query params
        $params = [
            'id'  => get_post_meta($order_id, 'mobbex_subscription_uid'),
            'sid' => get_post_meta($order_id, 'mobbex_subscriber_uid'),
            'eid' => $execution_id,
        ];

        // Retry execution
        $response = wp_remote_get(str_replace(['{id}', '{sid}', '{eid}'], $params, MOBBEX_RETRY_EXECUTION), [
            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],
        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result'])) {
                return true;
            }
        }

        throw new Exception(__('An error occurred in the execution', 'mobbex-subs-for-woocommerce'));
    }

    /**
     * Modify Subscription parameters using Mobbex API.
     * 
     * @param string|int $subscription_uid
     * @param array $params Parameters to modify
     * 
     * @return bool $result
     */
    public function modify_subscription($subscription_uid, $params)
    {
        if (!$this->is_ready()) {
            throw new Exception(__('Plugin is not ready', 'mobbex-subs-for-woocommerce'));
        }

        if (empty($subscription_uid) || empty($params)) {
            throw new Exception(__('Empty Subscription UID or params', 'mobbex-subs-for-woocommerce'));
        }

        // Modify Subscription
        $response = wp_remote_post(str_replace('{id}', $subscription_uid, MOBBEX_MODIFY_SUBSCRIPTION), [
            'headers' => [
                'cache-control'  => 'no-cache',
                'content-type'   => 'application/json',
                'x-api-key'      => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body'        => json_encode($params),
            'data_format' => 'body',
        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result']))
                return true;
        }

        throw new Exception(__('An error occurred in the execution', 'mobbex-subs-for-woocommerce'));
    }

    /**
     * Update order total.
     * 
     * @param WC_Order|WC_Subscription $order
     * @param int|string $total
     */
    public function update_order_total($order, $total)
    {
        if ($total == $order->get_total())
            return;

        // Create an item with total difference
        $item = new WC_Order_Item_Fee();

        $item->set_name(__('Modificación de monto', 'mobbex-subs-for-woocommerce'));
        $item->set_amount($total - $order->get_total());
        $item->set_total($total - $order->get_total());

        // Add the item and recalculate totals
        $order->add_item($item);
        $order->calculate_totals();
    }

    /*public function save_retried_execution($order_id, $execution_id)
    {
        $executions = get_post_meta($order_id, 'mbbxs_webhooks', true);

        foreach ($executions as $key => $execution) {
            if (isset($execution['data']['execution']['uid']) && $execution['data']['execution']['uid'] == $execution_id) {
                $executions[$key]['']
            }
        }
    }*/

    /**
     * Creates/Update a Mobbex Subscription & return Subscription class
     * 
     * @param int|string $product_id
     * @param string $sub_type
     * @param int|string $setup_fee
     * @param int|string $interval
     * @param int|string $trial
     * 
     * @return \MobbexSubscription|null
     */
    public function create_mobbex_subscription($product_id, $sub_type, $setup_fee, $interval = '', $trial = '')
    {
        $product = wc_get_product($product_id);
        $sub_name = $product->get_name();

        $subscription = new \MobbexSubscription(
            $product_id,
            "wc_order_{$product_id}_time_" . time(),
            $product->get_price(),
            $setup_fee,
            $sub_type,
            $sub_name,
            $sub_name,
            $interval,
            $trial,
            0,
        );

        if(!empty($subscription)){
            //Save Subscription 
            $subscription->save();
            return $subscription;
        }

        return null;
    }

    /**
     * Get a Subscription using UID.
     * 
     * @param string $uid
     * 
     * @return \MobbexSubscription|null
     */
    public function getSubscriptionByUid($uid)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " .$wpdb->prefix."mobbex_subscription"." WHERE uid='$uid'", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscription($result[0]['product_id']) : null;
    }

    /**
     * Get a Subscriber using UID.
     * 
     * @param string $uid
     * 
     * @return \MobbexSubscriber|null
     */
    public function getSubscriberByUid($uid)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " .$wpdb->prefix."mobbex_subscriber". " WHERE uid='$uid'", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscriber($result[0]['order_id']) : null;
    }
}