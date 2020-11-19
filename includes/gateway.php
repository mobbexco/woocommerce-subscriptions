<?php
require_once 'utils.php';

class WC_Gateway_Mobbex_Subs extends WC_Payment_Gateway
{
    public $supports = array(
        'subscriptions',
        'subscription_cancellation',
        'subscription_suspension',
        'subscription_reactivation',
        'subscription_amount_changes',
        'subscription_date_changes',
        'subscription_payment_method_change_customer',
    );

    public function __construct()
    {
        $this->id = MOBBEX_SUBS_WC_GATEWAY_ID;

        $this->method_title = __('Mobbex Subscriptions', 'mobbex-subs-for-woocommerce');
        $this->method_description = __('Mobbex Payment Gateway redirects customers to Mobbex to enter their payment information.', 'mobbex-subs-for-woocommerce');

        // Icon
        $this->icon = apply_filters('mobbex_subs_icon', plugin_dir_url(__FILE__) . '../assets/img/icon.png');

        // Generate admin fields
        $this->init_form_fields();
        $this->init_settings();

        // Get configuration
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');

        $this->use_button = false;
        $this->test_mode = ($this->get_option('test_mode') === 'yes');

        $this->api_key = $this->get_option('api-key');
        $this->access_token = $this->get_option('access-token');

        $this->helper = new MobbexHelper($this->api_key, $this->access_token);
        $this->error = false;
        if (empty($this->api_key) || empty($this->access_token)) {

            $this->error = true;
            MobbexHelper::notice('error', __('You need to specify an API Key and an Access Token.', 'mobbex-subs-for-woocommerce'));

        }

        // Always Required
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'scheduled_subscription_payment'], 10, 2);
        
        // Only if the plugin is enabled
        if (!$this->error && $this->isReady()) {
            add_action('woocommerce_api_mobbex_subs_return_url', [$this, 'mobbex_subs_return_url']);
            add_action('woocommerce_api_mobbex_subs_webhook', [$this, 'mobbex_subs_webhook']);
            if ($this->use_button) {
                add_action('woocommerce_after_checkout_form', [MobbexHelper::class, 'display_mobbex_button']);
                add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
            }
        }

    }

    public function init_form_fields()
    {

        $this->form_fields = [

            'enabled' => [

                'title' => __('Enable/Disable', 'mobbex-subs-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable checking out with Mobbex.', 'mobbex-subs-for-woocommerce'),
                'default' => 'yes',

            ],

            'title' => [

                'title' => __('Title', 'mobbex-subs-for-woocommerce'),
                'type' => 'text',
                'description' => __('This title will be shown on user checkout.', 'mobbex-subs-for-woocommerce'),
                'default' => __('Pay with Mobbex', 'mobbex-subs-for-woocommerce'),
                'desc_tip' => true,

            ],

            'api-key' => [

                'title' => __('API Key', 'mobbex-subs-for-woocommerce'),
                'description' => __('Your Mobbex API key.', 'mobbex-subs-for-woocommerce'),
                'type' => 'text',

            ],

            'access-token' => [

                'title' => __('Access Token', 'mobbex-subs-for-woocommerce'),
                'description' => __('Your Mobbex access token.', 'mobbex-subs-for-woocommerce'),
                'type' => 'text',

            ],

            'test_mode' => [

                'title' => __('Enable/Disable Test Mode', 'mobbex-subs-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode.', 'mobbex-subs-for-woocommerce'),
                'default' => 'no',

            ],

        ];

    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        return $saved;
    }

    public function process_payment($order_id)
    {
        if ($this->error) {
            return ['result' => 'error'];
        }

        global $woocommerce;

        $order = wc_get_order($order_id);
        $return_url = $this->helper->get_api_endpoint('mobbex_subs_return_url', $order_id);

        $is_subscription = wcs_is_subscription($order);
        $contains_subscription = wcs_order_contains_subscription($order_id);

        /** On Subscription registration */
        if ($contains_subscription) {

            $subscription_data = get_post_meta($order_id, 'mobbex_subscription', true);
            $subscriber_data = get_post_meta($order_id, 'mobbex_subscriber', true);

            if (empty($subscription_data)) {

                // Create mobbex subscription and save data
                $subscription_data = $this->get_subscription($order, $return_url);
                update_post_meta($order_id, 'mobbex_subscription', $subscription_data);
            }

            if (empty($subscriber_data)) {

                // Create mobbex subscriber and save data
                $subscriber_data = $this->get_subscriber($order, $subscription_data['uid']);
                update_post_meta($order_id, 'mobbex_subscriber', $subscriber_data);
            }

        }
        
        /** On payment method change */
        if ($is_subscription) {

            // Use parent order to get data
            $parent_order_id = $order->order->get_id();

            $subscription_data = get_post_meta($parent_order_id, 'mobbex_subscription', true);
            $subscriber_data = get_post_meta($parent_order_id, 'mobbex_subscriber', true);
        }

        if (empty($subscriber_data) || empty($subscription_data)) {
            return ['result' => 'error'];
        }

        if ($this->use_button) {

            return [
                'result' => 'success',
                'data' => $subscriber_data,
                'return_url' => $return_url,
                'redirect' => false,
            ];

        } else {
            return [
                'result' => 'success',
                'redirect' => $subscriber_data['sourceUrl'],
            ];
        }

    }

    public function mobbex_subs_webhook()
    {
        $id = $_REQUEST['mobbex_order_id'];
        $token = $_REQUEST['mobbex_token'];
        $data = $_POST['data'];

        $this->process_webhook($id, $token, $data);

        echo "WebHook OK: Mobbex for WooCommerce Subscriptions v" . MOBBEX_SUBS_VERSION;
        die();
    }

    public function process_webhook($id, $token, $data)
    {
        $status = $data['payment']['status']['code'];
        $type = $_POST['type'];

        if (empty($status) || empty($id) || empty($token) || empty($type)) {
            return false;
        }

        if (!$this->helper->valid_mobbex_token($token)) {
            return false;
        }

        $order = wc_get_order($id);
        update_post_meta($id, 'mobbex_webhook', $_POST);

        switch ($type) {
            case 'subscription:registration':
                
                // Get subscription from order id
                $subscriptions_ids = wcs_get_subscriptions_for_order($id);
                foreach($subscriptions_ids as $subscription_id => $subscription_obj) {}

                // Save subscription data
                update_post_meta($subscription_id, 'mobbex_subscription', $data);
                update_post_meta($subscription_id, 'mobbex_subscription_uid', $data['subscription']['uid']);
                update_post_meta($subscription_id, 'mobbex_subscriber_uid', $data['subscriber']['uid']);

                $order->add_order_note('Mobbex Subscription uid: ' . $data['subscription']['uid'] . '.');
                $order->add_order_note('Mobbex Subscriber uid:' . $data['subscriber']['uid'] . '.');

                if ($order->get_total() > 0) {
                    
                    // Execute first payment
                    $this->scheduled_subscription_payment($order->get_total(), $order);

                } elseif ($order->get_total() == 0){

                    $order->payment_complete();
                    WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
                }

            break;

            case 'subscription:execution':

                // Get subscription from order id
                $subscriptions_ids = wcs_get_subscriptions_for_order($id, ['order_type' => 'any']);
                foreach($subscriptions_ids as $subscription_id => $subscription_obj) {}

                if ($status == 4 || $status >= 200 && $status < 400) {
                    
                    // If is first payment
                    if ($subscription_obj->get_payment_count() < 1) {

                        $order->payment_complete();
                        WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
        
                    } else {
        
                        WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
        
                    }

                } else {
                    WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
                }

            break;
        }

        return true;
    }

    public function mobbex_subs_return_url()
    {
        $status = $_GET['status'];
        $id = $_GET['mobbex_order_id'];
        $token = $_GET['mobbex_token'];

        $error = false;
        if (empty($status) || empty($id) || empty($token)) {
            $error = "No se pudo validar la transacción. Contacte con el administrador de su sitio";
        }

        if (!$this->helper->valid_mobbex_token($token)) {
            $error = "Token de seguridad inválido.";
        }

        if (false !== $error) {
            return MobbexHelper::_redirect_to_cart_with_error($error);
        }

        $order = wc_get_order($id);

        if ($status == 0 || $status >= 400) {
            // Try to restore the cart here
            $redirect = $order->get_cancel_order_url();
        } else if ($status == 2 || $status == 3 || $status == 4 || $status >= 200 && $status < 400) {
            // Redirect
            $redirect = $order->get_checkout_order_received_url();
        }

        wp_safe_redirect($redirect);
    }

    public function isReady()
    {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (empty($this->api_key) || empty($this->access_token)) {
            return false;
        }

        return true;
    }

    public function payment_scripts()
    {
        if (is_wc_endpoint_url('order-received') || (!is_cart() && !is_checkout_pay_page())) {
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if (!$this->isReady()) {
            return;
        }

        $order_url = home_url('/mobbex?wc-ajax=checkout');

        // let's suppose it is our payment processor JavaScript that allows to obtain a token
        wp_enqueue_script('mobbex-button', 'https://res.mobbex.com/js/embed/mobbex.embed@' . MOBBEX_SUBS_EMBED_VERSION . '.js', null, MOBBEX_SUBS_EMBED_VERSION, false);

        // Inject our bootstrap JS to intercept the WC button press and invoke standard JS
        wp_register_script('mobbex-bootstrap', plugins_url('assets/js/mobbex.bootstrap.js', __FILE__), array('jquery'), MOBBEX_SUBS_VERSION, false);

        $mobbex_data = array(
            'order_url' => $order_url,
        );

        wp_localize_script('mobbex-bootstrap', 'mobbex_data', $mobbex_data);
        wp_enqueue_script('mobbex-bootstrap');
    }

    /**
     * Get subscriber.
     * 
     * @param WC_Order|WC_Abstract_Order $order
     * @param string $return_url
     * @return array|null $response_data
     */
    public function get_subscription($order, $return_url)
    {
        // Get subscription product name
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (WC_Subscriptions_Product::is_subscription($product)) {
                $subscription_name = $product->get_name();
            }
        }

        // Get subscription id for reference
        $subscriptions_ids = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
        foreach($subscriptions_ids as $subscription_id => $subscription_obj) {}

        $subscription_body = [
            'total' => $subscription_obj->get_total(),
            'currency' => 'ARS',
            'type' => 'manual',
            'name' =>  $subscription_name,
            'description' => $subscription_name,
            'limit' => 0,
            'webhook' => $this->helper->get_api_endpoint('mobbex_subs_webhook', $order->get_id()),
            'reference' => $subscription_id,
            'return_url' => $return_url,
            'features' => [],
            'sources' => [],
        ];

        // Create subscription
        $response = wp_remote_post(MOBBEX_CREATE_SUBSCRIPTION, [

            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body' => json_encode($subscription_body),
            'data_format' => 'body',

        ]);

        if (!is_wp_error($response)) {

            $response = json_decode($response['body'], true);
            $data = $response['data'];

            if ($data) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Get subscriber.
     * 
     * @param WC_Order|WC_Abstract_Order $order
     * @param integer $mbbx_subscription_uid
     * @return array|null $response_data
     */
    public function get_subscriber($order, $mbbx_subscription_uid)
    {
        $current_user = wp_get_current_user();
        $dni_key = !empty($this->custom_dni) ? $this->custom_dni : '_billing_dni';
        $reference = get_post_meta($order->get_id(), $dni_key, true) ? get_post_meta($order->get_id(), $dni_key, true) . $current_user->ID : $current_user->ID;

        $subscriber_body = [
            'customer' => [
                'name' => $current_user->display_name ? : $order->get_formatted_billing_full_name(),
                'email' => $current_user->user_email ? : $order->get_billing_email(),
                'phone' => get_user_meta($current_user->ID,'phone_number',true) ? : $order->get_billing_phone(),
                'uid' => $current_user->ID ? : null,
                'identification' => get_post_meta($order->get_id(), $dni_key, true),
            ],
            'reference' => $reference,
            'test' => $this->test_mode,
            'startDate' => [
                'day' => date('d'),
                'month' => date('m'),
            ],
        ];

        // Create subscriber
        $response = wp_remote_post(str_replace('{id}', $mbbx_subscription_uid, MOBBEX_CREATE_SUBSCRIBER), [

            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body' => json_encode($subscriber_body),
            'data_format' => 'body',

        ]);

        if (!is_wp_error($response)) {

            $response = json_decode($response['body'], true);
            $data = $response['data'];

            if ($data) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Executed by WooCommerce Subscriptions in each billing period.
     * 
     * @param integer $total
     * @param WC_Order|WC_Abstract_Order $order
     */
    public function scheduled_subscription_payment($total, $order) 
    {
        // Get subscription from order id
        $subscriptions_ids = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
        foreach($subscriptions_ids as $subscription_id => $subscription_obj) {}

        $mbbx_subscription_uid = get_post_meta($subscription_id, 'mobbex_subscription_uid', true);
        $mbbx_subscriber_uid = get_post_meta($subscription_id, 'mobbex_subscriber_uid', true);

        // if subscription is registered and is not empty
        if (!empty($mbbx_subscription_uid) && !empty($mbbx_subscriber_uid) && !empty($total)) {
            $result = $this->process_subscription_payment($mbbx_subscription_uid, $mbbx_subscriber_uid, $total);
        }
    
        if (!isset($result) || is_wp_error($result) || $result === false) {
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order); //check this in 400 status
        }
    }

    /**
     * Execute Subscription payment.
     * 
     * @param string $mbbx_subscription_uid
     * @param string $mbbx_subscriber_uid
     * @param integer $total
     * @return array|null $response_result
     */
    public function process_subscription_payment($mbbx_subscription_uid, $mbbx_subscriber_uid, $total)
    {
        $execution_body = [
            'total' => $total,
            'test' => $this->test_mode,
        ];

        $response = wp_remote_post(str_replace(['{id}', '{sid}'], [$mbbx_subscription_uid, $mbbx_subscriber_uid], MOBBEX_SUBSCRIPTION), [

            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body' => json_encode($execution_body),
            'data_format' => 'body',

        ]);

        if (!is_wp_error($response)) {

            $response = json_decode($response['body'], true);
            $result = $response['result'];

            if ($result) {
                return $result;
            }
        }

        return null;
    }
}
