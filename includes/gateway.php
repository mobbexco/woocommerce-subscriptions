<?php
require_once 'utils.php';

class WC_Gateway_Mbbx_Subs extends WC_Payment_Gateway
{
    public Mbbx_Subs_Helper $helper;

    public $supports = array(
        'products',
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

        $this->helper = new Mbbx_Subs_Helper();
        $this->error = false;

        if (!$this->helper->is_ready()) {
            $this->error = true;
            Mbbx_Subs_Helper::notice('error', __('You need to specify an API Key and an Access Token.', 'mobbex-subs-for-woocommerce'));
        }

        // Always Required
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'scheduled_subscription_payment'], 10, 2);

        // Only if the plugin is enabled
        if (!$this->error && $this->helper->is_ready()) {
            add_action('woocommerce_api_mobbex_subs_return_url', [$this, 'mobbex_subs_return_url']);
            add_action('woocommerce_api_mobbex_subs_webhook', [$this, 'mobbex_subs_webhook']);

            // Embed option
            if ($this->use_button) {
                add_action('woocommerce_after_checkout_form', [Mbbx_Subs_Helper::class, 'display_mobbex_button']);
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

            'integration' => [

                'title' => __('Integrate with', 'mobbex-subs-for-woocommerce'),
                'type' => 'select',
                'description' => __('Integrate this plugin with other subscriptions plugins. Detected integrations are displayed', 'mobbex-subs-for-woocommerce'),
                'desc_tip' => true,
                'options' => [
                    '' => __('None', 'mobbex-subs-for-woocommerce'),
                ]

            ],

            /*'type' => [

                'title'       => __('Subscription Manager', 'mobbex-subs-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Choose which platform is in charge of controlling the payment periods. By choosing Mobbex there will be fewer interval options.', 'mobbex-subs-for-woocommerce'), // Elige que plataforma se encarga de controlar los periodos de pago. Al elegir Mobbex habrán menos opciones de intervalos.
                'desc_tip'    => true,
                'options'     => [
                    'dynamic' => __('Mobbex Service', 'mobbex-subs-for-woocommerce'),
                    // TODO: 'manual'  => __('This plugin', 'mobbex-subs-for-woocommerce'),
                ],
                'default'     => 'dynamic',
            ],*/

            'title' => [

                'title' => __('Title', 'mobbex-subs-for-woocommerce'),
                'type' => 'text',
                'description' => __('This title will be shown on user checkout.', 'mobbex-subs-for-woocommerce'),
                'default' => __('Pay with Mobbex', 'mobbex-subs-for-woocommerce'),
                'desc_tip' => true,

            ],

            'test_mode' => [

                'title' => __('Enable/Disable Test Mode', 'mobbex-subs-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode.', 'mobbex-subs-for-woocommerce'),
                'default' => 'no',

            ],

        ];

        // Add integration options if are detected
        if (get_option('woocommerce_subscriptions_is_active')) {
            // Add option to select
            $this->form_fields['integration']['options']['wcs'] = __('WooCommerce Subscriptions', 'mobbex-subs-for-woocommerce');
        }

    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        return $saved;
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        if ($this->error) {
            return ['result' => 'error'];
        }

        $order = wc_get_order($order_id);
        $return_url = $this->helper->get_api_endpoint('mobbex_subs_return_url', $order_id);

        // Check if it's a payment method change or new payment
        if ($this->helper->is_subs_change_method()) {
            // Use parent order to get data from db
            $subscription_data = get_post_meta($order->order->get_id(), 'mobbex_subscription', true);
            $subscriber_data   = get_post_meta($order->order->get_id(), 'mobbex_subscriber', true);
        } else {
            // Register new subscription using API
            $subscription_data = get_post_meta($order_id, 'mobbex_subscription', true) ? : $this->get_subscription($order, $return_url);
            $subscriber_data   = get_post_meta($order_id, 'mobbex_subscriber', true) ? : $this->get_subscriber($order, $subscription_data['uid']);
        }

        // If data looks fine
        if (!empty($subscriber_data) && !empty($subscription_data)) {
            return [
                'result' => 'success',
                'redirect' => $subscriber_data['sourceUrl'],
            ];
        }

        return ['result' => 'error'];
    }

    public function mobbex_subs_webhook()
    {
        $id    = $_REQUEST['mobbex_order_id'];
        $token = $_REQUEST['mobbex_token'];
        $data  = $_POST['data'];

        $this->process_webhook($id, $token, $data);

        echo "WebHook OK: Mobbex for WooCommerce Subscriptions v" . MOBBEX_SUBS_VERSION;
        die();
    }

    public function process_webhook($id, $token, $data)
    {
        $type      = $_POST['type'];
        $status    = $data['payment']['status']['code'];
        $reference = $data['payment']['reference'];

        if (empty($status) || empty($id) || empty($token) || empty($type) || !$this->helper->valid_mobbex_token($token)) {
            return false;
        }

        $order    = wc_get_order($id);
        $state    = $this->helper->get_state($status);
        $webhooks = get_post_meta($id, 'mbbxs_webhooks', true) ?: [];

        if ($this->helper->is_wcs_active() && wcs_order_contains_subscription($id)) {
            // Get a WCS subscription if possible
            $subscriptions = wcs_get_subscriptions_for_order($id, ['order_type' => 'any']);
            $wcs_sub       = end($subscriptions);
            $wcs_sub_id    = key($subscriptions);
        } else if ($this->helper->has_subscription($id)) {
            // If has a mobbex subscription set standalone
            $standalone = true;
        } else {
            // No subscriptions
            return false;
        }

        switch ($type) {
            case 'subscription:registration':
                // Get registration result from context status
                $result = !empty($data['context']['status']) && $data['context']['status'] === 'success';

                // Add order notes
                $order->add_order_note('Mobbex Subscription uid: ' . $data['subscription']['uid'] . '.');
                $order->add_order_note('Mobbex Subscriber uid:' . $data['subscriber']['uid'] . '.');

                // Standalone mode
                if (isset($standalone)) {
                    if ($result) {
                        $order->payment_complete($id);
                    } else {
                        $order->update_status('failed', __('Validation failed', 'mobbex-subs-for-woocommerce'));
                    }
                } else if (isset($wcs_sub)) {
                    // Save subscription data
                    update_post_meta($wcs_sub_id, 'mobbex_subscription', $data);
                    update_post_meta($wcs_sub_id, 'mobbex_subscription_uid', $data['subscription']['uid']); // TODO: Save this also in standalone mode
                    update_post_meta($wcs_sub_id, 'mobbex_subscriber_uid', $data['subscriber']['uid']);

                    // Only if status is 200
                    if ($result) {
                        if ($order->get_total() > 0) {
                            // Execute first payment
                            $this->scheduled_subscription_payment($order->get_total(), $order);
                        } else if ($order->get_total() == 0){
                            $wcs_sub->payment_complete();
                        }
                    }
                }

                // Save validation data
                $webhooks['validations'][] = $_POST;
                break;

            case 'subscription:execution':
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
                        $order->update_status('failed', __('Execution failed', 'mobbex-subs-for-woocommerce'));
                    } else if (isset($wcs_sub)) {
                        $wcs_sub->payment_failed();
                    }
                }

                // Save payment data by reference
                $webhooks['payments'][$reference][] = $_POST;
                break;
        }

        // Update webhooks post meta
        update_post_meta($id, 'mbbxs_webhooks', $webhooks);

        return true;
    }

    public function mobbex_subs_return_url()
    {
        $id     = $_GET['mobbex_order_id'];
        $status = $_GET['status'];
        $token  = $_GET['mobbex_token'];

        $error = false;
        if (empty($status) || empty($id) || empty($token)) {
            $error = "No se pudo validar la transacción. Contacte con el administrador de su sitio";
        }

        if (!$this->helper->valid_mobbex_token($token)) {
            $error = "Token de seguridad inválido.";
        }

        if (false !== $error) {
            return Mbbx_Subs_Helper::_redirect_to_cart_with_error($error);
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

    public function payment_scripts()
    {
        if (is_wc_endpoint_url('order-received') || (!is_cart() && !is_checkout_pay_page())) {
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if (!$this->helper->is_ready()) {
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
     * Get subscription.
     * 
     * @param WC_Order|WC_Abstract_Order $order
     * @param string $return_url
     * @return array|null $response_data
     */
    public function get_subscription($order, $return_url)
    {
        $order_id = $order->get_id();
        $sub_type = isset($this->helper->type) ? $this->helper->type : 'dynamic';

        // Get subscription product name
        foreach ($order->get_items() as $item) {
            $product    = $item->get_product();
            $product_id = $product->get_id();

            if (Mbbx_Subs_Product::is_subscription($product_id)) {
                $sub_name   = $product->get_name();

                if ($sub_type === 'dynamic') {
                    $inverval  = implode(Mbbx_Subs_Product::get_charge_interval($product_id));
                    $trial     = Mbbx_Subs_Product::get_free_trial($product_id)['interval'];
                    $setup_fee = Mbbx_Subs_Product::get_signup_fee($product_id);
                }
            } else if (WC_Subscriptions_Product::is_subscription($product)) {
                $sub_name = $product->get_name();
            }
        }

        if ($this->helper->has_subscription($order_id)) {
            // Get total
            $total = $order->get_total();
        } else if ($this->helper->is_wcs_active() && wcs_order_contains_subscription($order_id)) {
            // Get wcs subscription
            $subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
            $wcs_sub       = end($subscriptions);

            $total = $wcs_sub->get_total();
        } else {
            return;
        }

        $body = [
            'total'       => $total,
            'currency'    => 'ARS',
            'type'        => isset($wcs_sub) ? 'manual' : 'dynamic',
            'name'        => $sub_name,
            'description' => $sub_name,
            'limit'       => 0,
            'webhook'     => $this->helper->get_api_endpoint('mobbex_subs_webhook', $order_id),
            'reference'   => "wc_order_{$order_id}_time_" . time(),
            'return_url'  => $return_url,
            'setupFee'    => isset($setup_fee) ? $setup_fee : '',
            'interval'    => isset($inverval) ? $inverval : '',
            'trial'       => isset($trial) ? $trial : '',
        ];

        // Create subscription
        $response = wp_remote_post(MOBBEX_CREATE_SUBSCRIPTION, [
            'headers' => [
                'cache-control'  => 'no-cache',
                'content-type'   => 'application/json',
                'x-api-key'      => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body'        => json_encode($body),
            'data_format' => 'body',
        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['data'])) {
                // Save data as post meta
                update_post_meta($order_id, 'mobbex_subscription', $response['data']);

                return $response['data'];
            }
        }

        return;
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

            if (!empty($response['data'])) {
                // Save data as post meta
                update_post_meta($order->get_id(), 'mobbex_subscriber', $response['data']);

                return $response['data'];
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
        $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
        $wcs_sub       = end($subscriptions);
        $wcs_sub_id    = key($subscriptions);

        $mbbx_subscription_uid = get_post_meta($wcs_sub_id, 'mobbex_subscription_uid', true);
        $mbbx_subscriber_uid = get_post_meta($wcs_sub_id, 'mobbex_subscriber_uid', true);

        // if subscription is registered and is not empty
        if (!empty($mbbx_subscription_uid) && !empty($mbbx_subscriber_uid) && !empty($total)) {
            // Execute charge manually
            $result = $this->helper->execute_charge($mbbx_subscription_uid, $mbbx_subscriber_uid, $total);
        }

        if (!isset($result) || is_wp_error($result) || $result === false)
            $wcs_sub->payment_failed(); //check this in 400 status
    }
}