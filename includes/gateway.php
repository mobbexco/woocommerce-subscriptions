<?php
require_once 'utils.php';

class WC_Gateway_Mbbx_Subs extends WC_Payment_Gateway
{
    /** @var Mbbxs_Helper */
    public $helper;

    /** Settings */
    public $embed;
    public $api_key;
    public $test_mode;
    public $access_token;
    public $send_subscriber_email;
    
    /** Errors array */
    public $error;

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

        $this->test_mode = ($this->get_option('test_mode') === 'yes');
        $this->embed = ($this->get_option('embed') === 'yes');

        $this->api_key = $this->get_option('api-key');
        $this->access_token = $this->get_option('access-token');

        $this->send_subscriber_email = ($this->get_option('send_subscriber_email') === 'yes');

        $this->helper = new Mbbxs_Helper();
        $this->error = false;

        if (!$this->helper->is_ready()) {
            $this->error = true;
            Mbbxs_Helper::notice('error', __('You need to specify an API Key and an Access Token.', 'mobbex-subs-for-woocommerce'));
        }

        // Always Required
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'scheduled_subscription_payment'], 10, 2);

        // Update subscription status
        add_action('woocommerce_subscription_status_active', [$this, 'update_subscriber_state']);
        add_action('woocommerce_subscription_status_cancelled', [$this, 'update_subscriber_state']);

        // Only if the plugin is enabled
        if (!$this->error && $this->helper->is_ready()) {
            add_action('woocommerce_api_mobbex_subs_return_url', [$this, 'mobbex_subs_return_url']);
            add_action('woocommerce_api_mobbex_subs_webhook', [$this, 'mobbex_subs_webhook']);
            add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
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

            'debug_mode' => [

                'title' => __('Enable/Disable Debug Mode', 'mobbex-subs-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Debug Mode.', 'mobbex-subs-for-woocommerce'),
                'default' => 'no',

            ],

            'embed' => [

                'title' => __('Enable/Disable Embed Mode', 'mobbex-subs-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Embed Mode.', 'mobbex-subs-for-woocommerce'),
                'default' => 'yes',

            ],

            'send_subscriber_email' => [

                'title' => __('Enable emails to Subscriber', 'mobbex-subs-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Subscriber Emails', 'mobbex-subs-for-woocommerce'),
                'default' => 'yes'

            ]
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
        mbbxs_log('debug', "Process Payment. Init. Order ID: $order_id");

        if ($this->error) {
            mbbxs_log('error', "Process Payment. Error. Order ID: $order_id", $this->error);

            return ['result' => 'error'];
        }

        $order = wc_get_order($order_id);

        //Save order id in session
        WC()->session->set('order_id', $order_id);
        mbbxs_log('debug', "Process Payment. Order instanced and session updated. Order ID: $order_id", [$order->get_id()]);

        //Check if it's a payment method change, a manual renewal or new payment
        if ($this->helper->is_subs_change_method()) {
            mbbxs_log('debug', "Process Payment for order id $order_id. is_subs_change_method");
            $order->add_order_note("Payment method change detected. Redirecting to Mobbex");

            $this->helper->maybe_migrate_subscriptions($order);
            $subscription = $this->get_subscription($order);
            $subscriber   = $this->get_subscriber($order, $subscription->uid);
        } else if ($this->helper->is_wcs_active() && wcs_order_contains_renewal($order)) {
            mbbxs_log('debug', "Process Payment for order id $order_id. order_contains_renewal. Scheduled Payment");
            $order->add_order_note("Manual order renewal detected. Executing scheduled payment");

            $result = $this->scheduled_subscription_payment($order->get_total(), $order);

            mbbxs_log('debug', "Process Payment for order id $order_id. order_contains_renewal result. Scheduled Payment", [
                'result'   => $result ? 'success' : 'error',
                'redirect' => $result ? $order->get_checkout_order_received_url() : Mbbxs_Helper::_redirect_to_cart_with_error('Error al intentar realizar el cobro de la suscripción'),
            ]);
    
            return [
                'result'   => $result === false || is_wp_error($result) ? 'error' : 'success',
                'redirect' => $result === false || is_wp_error($result) ? Mbbxs_Helper::_redirect_to_cart_with_error('Error al intentar realizar el cobro de la suscripción') : $order->get_checkout_order_received_url(),
            ];
        } else {
            mbbxs_log('debug', "Process Payment for order id $order_id. else");

            $subscription = $this->get_subscription($order);
            $subscriber   = $this->get_subscriber($order, $subscription->uid);
        }

        mbbxs_log('debug', "Process Payment for order id $order_id. common result", [
            'result'     => 'success',
            'redirect'   => $this->helper->embed ? false : $subscriber->source_url,
            'return_url' => $subscription->return_url,
            'data'       => [
                'id'  => $subscription->uid,
                'sid' => $subscriber->uid,
                'url' => $subscriber->source_url
            ],
        ]);

        // If data looks fine
        if (!empty($subscriber->uid) && !empty($subscription->uid)) {
            return [
                'result'     => 'success',
                'redirect'   => $this->helper->embed ? false : $subscriber->source_url,
                'return_url' => $subscription->return_url,
                'data'       => [
                    'id'  => $subscription->uid,
                    'sid' => $subscriber->uid,
                    'url' => $subscriber->source_url
                ],
            ];
        }

        return ['result' => 'error'];
    }

    public function mobbex_subs_webhook()
    {
        $token    = $_REQUEST['mobbex_token'];
        $id       = isset($_REQUEST['mobbex_order_id']) ? $_REQUEST['mobbex_order_id'] : null;
        $postData = isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json' ? json_decode(file_get_contents('php://input'), true) : $_POST;
        mbbxs_log('debug', "Process Webhook. Init. Order ID $id");

        $this->process_webhook($token, $postData['data'], $postData['type'], $id);

        echo "WebHook OK: Mobbex for WooCommerce Subscriptions v" . MOBBEX_SUBS_VERSION;
        die();
    }

    public function process_webhook($token, $data, $type, $id)
    {
        $status = $data['payment']['status']['code'];

        if (empty($status) || empty($token) || !$type || empty($type) || !$this->helper->valid_mobbex_token($token)) {
            mbbxs_log('error', "Process Webhook. Invalid webhook. Order ID $id", [$status, $token, $type, $this->helper->valid_mobbex_token($token)]);

            return false;
        }

        //Compatibility with 2.x subscriptions
        if($id){
            $order = wc_get_order($id);

            // If there is an order, it stores the order subscriptions in the table  
            if($order)
                $this->helper->maybe_migrate_subscriptions($order);
        }

        mbbxs_log('debug', "Process Webhook. Maybe migrated subscriptions. Order ID $id", [$order]);

        $subscription = \MobbexSubscription::get_by_uid($data['subscription']['uid']);
        $subscriber   = \MobbexSubscriber::get_by_uid($data['subscriber']['uid']);
        $order_id     = $subscriber->order_id;
        $order        = wc_get_order($order_id);
        $state        = $this->helper->get_state($status);
        $dates        = $subscription->calculateDates();

        mbbxs_log('debug', "Process Webhook. Obtained data. Order ID $id", [
            $subscription ? $subscription->uid: null,
            $subscriber ? $subscriber->uid : null,
            $order_id,
            $order->get_id(),
            $state,
            $dates
        ]);

        $order->add_order_note("Received data to process Mobbex webhook");

        if ($this->helper->is_wcs_active() && wcs_order_contains_subscription($order_id)) {
            mbbxs_log('debug', "Process Webhook. Order Contains Sub. Order ID $id");

            // Get a WCS subscription if possible
            $subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
            $wcs_sub       = end($subscriptions);
        } else if ($this->helper->has_subscription($order_id)) {
            mbbxs_log('debug', "Process Webhook. Standalone mode detected. Order ID $id");

            // If has a mobbex subscription set standalone
            $standalone = true;
        } else {
            mbbxs_log('error', "Process Webhook. No subscriptions detected. Order ID $id");

            // No subscriptions
            return false;
        }

        if($type === 'subscription:registration' || $type === 'subscription:execution'){
            $order->add_order_note("Processing webhook with payment information. Type of webhook $type");

            if($type === 'subscription:registration'){
                mbbxs_log('debug', "Process Webhook. Registration start. Order ID $id");
                
                // Avoid duplicate registration process
                if ($subscriber->register_data) {
                    $order->add_order_note('Avoid attempt to re-register Subscriber UID: ' . $data['subscriber']['uid']);
                    return false;
                }
                // Get registration result from context status
                $result = !empty($data['context']['status']) && $data['context']['status'] === 'success';
    
                // Save registration data and update subscriber state
                $subscriber->register_data = json_encode($data);
                $subscriber->state         = $status;
                $subscriber->start_date    = $dates['current'];
    
                // Add order notes
                $order->add_order_note('Mobbex Subscription UID: ' . $data['subscription']['uid']);
                $order->add_order_note('Mobbex Subscriber UID:' . $data['subscriber']['uid']);
    
                // Standalone mode
                if (isset($standalone)) {
                    $order->add_order_note("Standalone mode detected. Updating order status. Status " . !empty($data['context']['status']) ? $data['context']['status'] : '');
                    mbbxs_log('debug', "Process Webhook. Standalone mode detected. Updating order status. Status " . (!empty($data['context']['status']) ? $data['context']['status'] : '') . ". Order ID $id");

                    if ($result) {
                        $order->payment_complete($order_id);
                    } else {
                        $order->update_status('failed', __('Validation failed', 'mobbex-subs-for-woocommerce'));
                    }
                } else if (isset($wcs_sub)) {
                    $order->add_order_note("WCS mode detected. Updating order status. Status " . !empty($data['context']['status']) ? $data['context']['status'] : '');
                    mbbxs_log('debug', "Process Webhook. WCS mode detected. Updating order status. Status " . (!empty($data['context']['status']) ? $data['context']['status'] : '') . ". Order ID $id");

                    // Enable subscription
                    if ($result)
                        $wcs_sub->payment_complete();
                }

                mbbxs_log('debug', "Process Webhook. Order status updated ok. Order ID $id");
            }
        
            //Add order status
            // If status look fine
            if ($state == 'approved' || $state == 'on-hold') {
                // Mark as payment complete
                if (isset($standalone)) {
                    $order->add_order_note("Standalone mode detected. Updating order status. State $state");
                    mbbxs_log('debug', "Standalone mode detected. Updating order status. State $state");

                    $order->payment_complete();
                } else if (isset($wcs_sub)) {
                    $order->add_order_note("WCS mode detected. Updating order status. State $state");
                    mbbxs_log('debug', "WCS mode detected. Updating order status. State $state");

                    $wcs_sub->payment_complete();
                }
            } else {
                // Mark as payment failed
                if (isset($standalone)) {
                    $order->add_order_note("Standalone mode detected. Updating order status to failed. State $state");
                    mbbxs_log('debug', "Standalone mode detected. Updating order status to failed. State $state");

                    $order->update_status('failed', __('Execution failed', 'mobbex-subs-for-woocommerce'));
                } else if (isset($wcs_sub)) {
                    $order->add_order_note("WCS mode detected. Updating order status to failed. State $state");
                    mbbxs_log('debug', "WCS mode detected. Updating order status to failed. State $state");

                    $wcs_sub->payment_failed();
                }
            }

            mbbxs_log('debug', "Process Webhook. Order state updated ok. Order ID $id");
        }


            
        // Update execution dates
        $subscriber->last_execution = $dates['current'];
        $subscriber->next_execution = $dates['next'];

        mbbxs_log('debug', "Process Webhook. Before save subscriber. Order ID $id");

        //Save the subscriber with updated data
        $subscriber->save(false);
        mbbxs_log('debug', "Process Webhook. Before save execution. Order ID $id");

        // Save webhooks data in execution table
        $subscriber->saveExecution($data, $order_id, $subscriber->last_execution);

        mbbxs_log('debug', "Process Webhook. Result ok. Order ID $id");

        return true;
    }

    public function mobbex_subs_return_url()
    {
        $id     = WC()->session->get('order_id');
        $status = $_GET['status'];
        $token  = $_GET['mobbex_token'];
        $error  = false;

        if (empty($status) || empty($id) || empty($token)) {
            $error = "No se pudo validar la transacción. Contacte con el administrador de su sitio";
        }

        if (!$this->helper->valid_mobbex_token($token)) {
            $error = "Token de seguridad inválido.";
        }

        if (false !== $error) {
            return Mbbxs_Helper::_redirect_to_cart_with_error($error);
        }

        //Get the order from id
        $order = wc_get_order( $id );

        if ($status == 0 || $status >= 400) {
            // Try to restore the cart here
            $redirect = $order->get_cancel_order_url_raw();
        } else if ($status == 2 || $status == 3 || $status == 4 || $status >= 200 && $status < 400) {
            // Redirect
            $redirect = $order->get_checkout_order_received_url();
        }

        wp_redirect($redirect);
    }

    public function payment_scripts()
    {
        $dir_url = str_replace('/includes', '', plugin_dir_url(__FILE__));

        // Only if directory url looks good
        if (empty($dir_url) || substr($dir_url, -1) != '/')
            return apply_filters('simple_history_log', 'Mobbex Subs. Enqueue Error: Invalid dir_url' . $dir_url, null, 'error');

        // Checkout page
        if (!is_checkout())
            return;

        // Exclude scripts from cache plugins minification
        !defined('DONOTCACHEPAGE') && define('DONOTCACHEPAGE', true);
        !defined('DONOTMINIFY') && define('DONOTMINIFY', true);

        wp_enqueue_script('mobbex-embed', 'https://res.mobbex.com/js/embed/mobbex.embed@1.0.23.js', null, \Mbbx_Subs_Gateway::$version);
        wp_enqueue_script('mobbex-sdk', 'https://res.mobbex.com/js/sdk/mobbex@1.1.0.js', null, \Mbbx_Subs_Gateway::$version);

        // Enqueue payment asset files
        wp_enqueue_style('mobbex-subs-checkout-style', $dir_url . 'assets/css/checkout.css', null, \Mbbx_Subs_Gateway::$version);
        wp_register_script('mobbex-subs-checkout-script', $dir_url . 'assets/js/checkout.js', ['jquery'], \Mbbx_Subs_Gateway::$version);

        wp_localize_script('mobbex-subs-checkout-script', 'mobbex_data', [
            'is_pay_for_order' => !empty($_GET['pay_for_order']),
        ]);
        wp_enqueue_script('mobbex-subs-checkout-script');
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
        mbbxs_log('debug', "Get subscription. Order ID: " . $order->get_id());

        $order_id    = $order->get_id();
        $sub_options = [
            'type'     => $this->helper->is_wcs_active() ? 'manual' : 'dynamic',
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

            if (Mbbx_Subs_Product::is_subscription($product_id)) {
                    $sub_options['interval']  = implode(Mbbx_Subs_Product::get_charge_interval($product_id));
                    $sub_options['trial']     = Mbbx_Subs_Product::get_free_trial($product_id)['interval'];
                    $sub_options['setup_fee'] = Mbbx_Subs_Product::get_signup_fee($product_id);
                    $sub_options['test']      = Mbbx_Subs_Product::get_test_mode($product_id);
            }

            if ($this->helper->is_wcs_active() && !\WC_Subscriptions_Product::is_subscription($product) && !$this->helper->has_subscription($order_id)) {
                mbbxs_log('error', "Subscription not found in product. Order ID: " . $order->get_id(), [$product_id, $this->helper->is_wcs_active(), \WC_Subscriptions_Product::is_subscription($product), $this->helper->has_subscription($order_id)]);

                apply_filters('simple_history_log', __METHOD__ . ": Order #$order_id does not contain any Subscription", null, 'error');
                return;
            }
        }

        mbbxs_log('debug', "Get subscription. Before creation. Order ID: " . $order->get_id(), $sub_options);
        $subscription = $this->helper->create_mobbex_subscription($sub_options);
        mbbxs_log('debug', "Get subscription. After creation. Order ID: " . $order->get_id(), !empty($subscription->uid) ? $subscription->uid : null);

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
        mbbxs_log('debug', "Get subscriber. Init. Order ID: " . $order->get_id() . ". Subscription UID: $mbbx_subscription_uid");

        $order_id     = $order->get_id();
        $current_user = wp_get_current_user();

        $dni_key   = !empty($this->helper->custom_dni) ? $this->helper->custom_dni : '_billing_dni';
        $reference = get_post_meta($order_id, $dni_key, true) ? get_post_meta($order_id, $dni_key, true) . $current_user->ID : $current_user->ID;

        $name        = $current_user->display_name ?: $order->get_formatted_billing_full_name();
        $email       = $current_user->user_email ?: $order->get_billing_email();
        $phone       = get_user_meta($current_user->ID, 'phone_number', true) ?: $order->get_billing_phone();
        $dni         = get_post_meta($order_id, $dni_key, true);
        $customer_id = $current_user->ID ?: null;

        mbbxs_log('debug', "Get subscriber. Before creation. Order ID: " . $order->get_id() . ". Subscription UID: $mbbx_subscription_uid", [
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
        $subscriber = new \MobbexSubscriber(
            $order_id,
            $mbbx_subscription_uid,
            $reference,
            $name,
            $email,
            $phone,
            $dni,
            $customer_id
        );

        mbbxs_log('debug', "Get subscriber. After creation. Order ID: " . $order->get_id() . '. Subscription UID: ' .  $mbbx_subscription_uid);

        // Save subscriber and sync with mobbex
        $result = $subscriber->save();

        mbbxs_log('debug', "Get subscriber. After save. Order ID: " . $order->get_id() . '. Subscription UID: ' .  $mbbx_subscription_uid, [$result, $subscriber]);

        if ($result)
            return $subscriber;

        return null;
    }

    /**
     * Executed by WooCommerce Subscriptions in each billing period.
     * 
     * @param integer $total
     * @param WC_Order|WC_Abstract_Order $order
     * 
     * @return bool Result of charge execution.
     */
    public function scheduled_subscription_payment($total, $order)
    {
        mbbxs_log('debug', 'Gateway (Hook) > scheduled_subscription_payment', ['orden_payment_method' => $order->get_payment_method()]);
        // Return if payment method is not Mobbex
        if (MOBBEX_SUBS_WC_GATEWAY_ID != $order->get_payment_method()){
            mbbxs_log('debug', "El método de pago no es Mobbex => Gateway (Hook) > scheduled_subscription_payment is bypassed.", ['payment_method' => $order->get_payment_method()]);
            return;
        }

        mbbxs_log('debug', "Scheduled Payment. Init for $ $total. Order ID " . $order->get_id());
 
        $order->add_order_note("Processing scheduled payment for $ $total");

        // Get subscription from order id
        $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']); 

        mbbxs_log('debug', "Scheduled Payment. Getting subscriptions. Order ID " . $order->get_id(), $subscriptions);
        $wcs_sub = end($subscriptions);
        mbbxs_log('debug', "Scheduled Payment. Subscription extract. Order ID " . $order->get_id(), [$wcs_sub, $wcs_sub->get_id(), $wcs_sub->order ? $wcs_sub->order->get_id() : null]);

        //Migrate subscriptions
        $this->helper->maybe_migrate_subscriptions($wcs_sub->order);
        
        //get mobbex subscriber & subscription
        $subscription = $this->get_subscription($order, $wcs_sub->order->get_id());
        mbbxs_log('debug', "Scheduled Payment. Subscription obtained succesfuly. Order ID " . $order->get_id(), $subscription->uid);


        if(!empty($subscription->uid))
            $subscriber = new \MobbexSubscriber($wcs_sub->order->get_id());

        mbbxs_log('debug', "Scheduled Payment. Subscriber obtained succesfuly. Order ID " . $order->get_id(), [!empty($subscriber->uid) ? $subscriber->uid : null, $total]);

        // if subscription is registered and is not empty
        if (empty($subscription->uid) || empty($subscriber->uid) || empty($total)) {
            $order->add_order_note("Error executing subscription. Empty subscription data or total" . $total);
            return false;
        }

        try {
            $order->add_order_note("Executing charge for Mobbex Subscription $subscription->uid and Mobbex Subscriber $subscriber->uid");
            $result = $subscriber->execute_charge(
                implode('_', [$subscription->uid, $subscriber->uid, $order->get_id()]),
                $total
            );

            $order->add_order_note("Charge execution raw result: " . (empty($result['result']) ? 'unknown' : 'success'));

            // Throw exception if result is invalid
            if (!isset($result['result']))
                throw new \Exception(sprintf(
                    'Mobbex request error #%s: %s %s',
                    isset($result['code']) ? $result['code'] : 'NOCODE',
                    isset($result['error']) ? $result['error'] : 'NOERROR',
                    isset($result['status_message']) ? $result['status_message'] : 'NOMESSAGE'
                ), 0);

            // Return true if is in progress
            if (isset($result['code']) && $result['code'] === 'SUBSCRIPTIONS:EXECUTION_ALREADY_IN_PROGRESS') {
                $order->add_order_note("Charge execution result: Already in progress");

                return true;
            }

            // Throw exception on any other false status
            if (!$result['result'])
                throw new \Exception(sprintf(
                    'Mobbex request error #%s: %s %s',
                    isset($result['code']) ? $result['code'] : 'NOCODE',
                    isset($result['error']) ? $result['error'] : 'NOERROR',
                    isset($result['status_message']) ? $result['status_message'] : 'NOMESSAGE'
                ), 0);

            mbbxs_log('debug', "Scheduled Payment. Execute Charge Result $subscription->uid $subscriber->uid. Order ID " . $order->get_id(), $result);

            return true;
        } catch (\Exception $e) {
            $order->add_order_note("Charge execution error: " . $e->getMessage());
            $wcs_sub->payment_failed();
            mbbxs_log('error', "Charge execution error: " . $e->getMessage());

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
        mbbxs_log('debug', 'Hook > update_subscriber_state', ['subscripion' => $subscription->get_parent()->get_id()]);
        // Gets order
        $order_id = $subscription->get_parent()->get_id();
        $order    = wc_get_order($order_id);

        mbbxs_log('debug', 'Hook > update_subscriber_state', ['orden_payment_method' => $order->get_payment_method()]);

        // Returns if payment method is not Mobbex
        if (MOBBEX_SUBS_WC_GATEWAY_ID != $order->get_payment_method()){
            mbbxs_log('debug', "El método de pago no es Mobbex => update_subscriber_state is bypassed.", ['payment_method' => $order->get_payment_method()]);
            return;
        }

        try {
            // Checks that subscription or order id is nor null
            if (!$subscription || !$subscription->get_parent())
                throw new \Exception(__('Mobbex error: Subscription or parent order not found on state update', 'mobbex-subs-for-woocommerce'));

            // Gets subscription status
            $status   = $subscription->get_status();

                        // Get susbscriber
            $subscriber = new \MobbexSubscriber($order_id);

            // Update subscriber state through the corresponding endpoint
            $subscriber->update_status($status);
            
        } catch (\Exception $e) {
            $subscription->add_order_note(__('Error modifying subscriber status: ', 'mobbex-subs-for-woocommerce') . $e->getMessage());
        }
    }
}
