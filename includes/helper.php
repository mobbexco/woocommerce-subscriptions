<?php
require_once 'utils.php';

class Mbbxs_Helper
{
    public $title;
    public $embed;
    public $enabled;
    public $api_key;
    public $currency;
    public $test_mode;
    public $debug_mode;
    public $integration;
    public $access_token;
    public $send_subscriber_email;

    public static $periods = [
        'd' => 'day',
        'm' => 'month',
        'y' => 'year'
    ];

    public static $execution_status = [
        'failed' => [
            'failed',
            'error',
            'error_no_reference',
            'error_no_payment_method',
            'error_expired_sources',
            'error_payment',
            'error_too_many_retries',
            'inactive_subscription',
            'inactive_subscriber',
        ],
        'pending' => [
            'pending',
            'processing',
            'scheduled',
            'notretry',
            'retried',
            'auto_retried',
            'trial',
            'not_started',
        ],
        'finished' => [
            'paid',
            'deleted',
            'suspended',
            'finished',
        ]
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

    public function get_api_endpoint($endpoint)
    {
        $query = [
            "wc-api"       => $endpoint,
            'platform'     => "woocommerce",
            "version"      => MOBBEX_SUBS_VERSION,
            'mobbex_token' => $this->generate_token(),
        ];

        if ($this->debug_mode && $endpoint == 'mobbex_subs_webhook')
            $query['XDEBUG_SESSION_START'] = 'PHPSTORM';

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

        return is_a($pending_order, 'WC_Subscription')
            || \WC_Subscriptions_Cart::cart_contains_subscription()
            || wcs_cart_contains_renewal()
            || ($pending_order && wcs_order_contains_subscription($pending_order));
    }

    /**
	 * Checks if page is pay for order and change subs payment page.
	 */
    public static function is_method_change()
    {
		return (isset($_GET['pay_for_order']) && isset($_GET['change_payment_method']));
	}

    /**
	 * Get payment state from Mobbex status code.
     * 
     * @param int|string $status
     * 
     * @return string "approved" | "on-hold" | "failed"
	 */
    public static function get_state($status)
    {
        if ($status == 2 || $status == 3 || $status == 100) {
            return 'on-hold';
        } else if ($status == 4 || $status >= 200 && $status < 400) {
            return 'approved';
        } else {
            return 'failed';
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
     * @param array $sub_options
     * 
     * @return \MobbexSubscription|null
     */
    public function create_mobbex_subscription($sub_options)
    {
        $subscription = new \MobbexSubscription(
            $sub_options['post_id'],
            $sub_options['reference'],
            $sub_options['price'],
            $sub_options['signup_fee'],
            $sub_options['type'],
            $sub_options['name'],
            $sub_options['name'],
            $sub_options['interval'],
            $sub_options['trial'],
            0,
        );
        mbbxs_log('debug', 'helper > create_mobbex_subscription - subscription: ' . $subscription->product_id , ['subscription' => $subscription]);

        if(!empty($subscription)){
            //Save Subscription 
            $subscription->save();
            return $subscription;
        }

        return null;
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
        if (!$this->is_wcs_active())
            return $product_id;

        $wcs_sub = $this->get_wcs_subscription($order->get_id());
        $parent = $wcs_sub->get_parent();

        if ($parent && \MobbexSubscription::is_stored($parent->get_id()))
            return $parent->get_id();

        return $product_id;
    }

    /**
     * Store old 2.x subscribers & subscriptions in database
     * 
     * @param WC_Order|WC_Abstract_Order $order
     */
    public function maybe_migrate_subscriptions($order)
    {
        if (!$order || !method_exists($order, 'get_id'))
            throw new \Exception('Invalid order object');

        $old_subscription = get_post_meta($order->get_id(), 'mobbex_subscription', true);
        $old_subscriber   = get_post_meta($order->get_id(), 'mobbex_subscriber', true);

        if ($old_subscription){
            $order->add_order_note("Old Subscription detected. Making migration");

            $subscription = new \MobbexSubscription(
                $order->get_id(),
                "wc_order_{$order->get_id()}", 
                isset($old_subscription['total']) ? $old_subscription['total'] : '',
                isset($old_subscription['setupFee']) ? $old_subscription['setupFee'] : '',
                $this->is_wcs_active() ? 'manual' : 'dynamic',
                isset($old_subscription['name']) ? $old_subscription['name'] : '',
                isset($old_subscription['description']) ? $old_subscription['description'] : '',
                isset($old_subscription['interval']) ? $old_subscription['interval'] : '',
                isset($old_subscription['trial']) ? $old_subscription['trial'] : '',
                isset($old_subscription['limit']) ? $old_subscription['limit'] : ''
            );

            //Set uid
            $subscription->uid        = isset($old_subscription['uid']) ? $old_subscription['uid'] : '';
            $subscription->return_url = isset($old_subscription['return_url']) ? $old_subscription['return_url'] : '';
            $subscription->webhook    = isset($old_subscription['webhook']) ? $old_subscription['webhook'] : '';

            mbbxs_log('debug', "Migrating subscription. Before save. Order ID " . $order->get_id());
            $subscription->save();

            update_post_meta($order->get_id(), 'mobbex_subscription', '');
            $order->add_order_note("Old Subscription detected. Migration done");
            $order->save();
        }

        if ($old_subscriber){
            $order->add_order_note("Old Subscriber detected. Making migration");

            if (empty($old_subscription['uid'])) {
                // Try to obatin from 
                $new_subscription = new \MobbexSubscription($order->get_id());
                $old_subscription['uid'] = $new_subscription->uid;
            }

            $subscriber = new \MobbexSubscriber(
                $order->get_id(),
                isset($old_subscription['uid']) ? $old_subscription['uid'] : '',
                isset($old_subscriber['reference']) ? $old_subscriber['reference'] : '',
                $order->get_billing_first_name(),
                $order->get_billing_email(),
                $order->get_billing_phone(),
                get_post_meta($order->get_id(), !empty($this->helper->custom_dni) ? $this->helper->custom_dni : '_billing_dni', true),
                $order->get_customer_id()
            );

            //set other data
            $subscriber->uid         = isset($old_subscriber['uid']) ? $old_subscriber['uid'] : '';
            $subscriber->source_url  = isset($old_subscriber['sourceUrl']) ? $old_subscriber['sourceUrl'] : '';
            $subscriber->control_url = isset($old_subscriber['subscriberUrl']) ? $old_subscriber['subscriberUrl'] : '';

            mbbxs_log('debug', "Migrating subscriber. Before save. Order ID " . $order->get_id());
            $subscriber->save(false);

            update_post_meta($order->get_id(), 'mobbex_subscriber', '');
            $order->add_order_note("Old Subscriber migration done");
            $order->save();
        }
    }

    /**
	 * Checks to see if the current order, and a fresh copy of the order from the database are paid.
	 *
	 * @param WC_Order $order The order being checked.
	 *
	 * @return bool True if it has a paid status, false if not.
	 */
	public function is_order_paid($order) {
		wp_cache_delete($order->get_id(), 'posts');

		// Read the latest order properties from the database to avoid race conditions if webhook was handled during this request.
		$clone_order = clone $order;
		$clone_order->get_data_store()->read($clone_order);

		// Check if the order is already complete.
		if ( function_exists('wc_get_is_paid_statuses')) {
			if ( $order->has_status(wc_get_is_paid_statuses())
				|| $clone_order->has_status(wc_get_is_paid_statuses()) ) {
				return true;
			}
		}

		return false;
	}

    /**
     * Get WCS Subscription from Order ID.
     * 
     * @param int $order_id
     * 
     * @return \WC_Subscription|null
     */
    public function get_wcs_subscription($order_id)
    {
        if (!$this->is_wcs_active() || !$order_id || !is_numeric($order_id))
            return null;

        $subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
        return end($subscriptions) ?: null;
    }

    /**
     * Update order status securely.
     * 
     * @param WC_Order $order
     * @param string $state
     * @param string $message
     */
    public function update_order_status($order, $state, $message)
    {
        if ($state == 'approved') {
            if ($this->is_order_paid($order)) {
                $order->add_order_note("Order is already paid. $message");
            } else {
                $order->add_order_note($message);
                $order->payment_complete();
            }
        } else {
            if ($order->has_status($state)) {
                $order->add_order_note("Order is already $state. $message");
            } else {
                $order->update_status($state, $message);
            }
        }
    }

    /**
     * Get mobbex valid interval for a given wcs interval and period.
     * 
     * @param int $interval
     * @param string $period
     * 
     * @return string
     */
    public function get_valid_interval($interval, $period)
    {
        if (empty($interval) || empty($period))
            throw new \Exception('Empty interval or period');

        // Valid intervals ordered by period
        $valid_intervals = [
            'day' => [
                'code'      => 'd',
                'intervals' => [7, 15]
            ],
            'month' => [
                'code'      => 'm',
                'intervals' => [1, 2, 3, 6]
            ],
            'year' => [
                'code'      => 'y',
                'intervals' => [1]
            ]
        ];

        // Some week intervals can be transformed to other supported
        if ($period === 'week') {
            $transformable_intervals = [
                1 => ['day', 7],
                2 => ['day', 15],
                4 => ['month', 1]
            ];

            if (isset($transformable_intervals[$interval])) {
                $period = $transformable_intervals[$interval][0];
                $interval = $transformable_intervals[$interval][1];
            }
        }

        if (empty($valid_intervals[$period]))
            throw new \Exception("Invalid period $period with interval $interval");

        if (!in_array($interval, $valid_intervals[$period]['intervals']))
            throw new \Exception("Invalid interval $interval for period $period");

        return  $interval . $valid_intervals[$period]['code'];
    }

    /**
     * Get the order ID from a Mobbex execution reference.
     * 
     * @param string $ref Subscriber execution reference.
     * 
     * @return string|null
     */
    public function get_order_id_from_execution_reference($ref)
    {
        if (empty($ref))
            return null;

        $reference_parts = explode('_', $ref);

        // Check reference format
        if (count($reference_parts) < 3 || !is_numeric($reference_parts[2]))
            return null;

        return $reference_parts[2];
    }
}