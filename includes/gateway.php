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
            'redirect'   => $this->embed ? false : $subscriber->source_url,
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
                'redirect'   => $this->embed ? false : $subscriber->source_url,
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
        $id      = isset($_REQUEST['mobbex_order_id']) ? $_REQUEST['mobbex_order_id'] : null;
        $token   = isset($_REQUEST['mobbex_token']) ? $_REQUEST['mobbex_token'] : null;
        $payload = isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json' ? json_decode(file_get_contents('php://input'), true) : $_POST;

        if (!$token)
            mbbx_http_error(400, 'Bad request. Missing token');

        if (!$this->helper->valid_mobbex_token($token))
            mbbx_http_error(401, 'Unauthorized. Invalid token', $token);

        if (empty($payload['data']) || empty($payload['type']))
            mbbx_http_error(400, 'Bad request. Missing data or type');

        if (empty($payload['data']['subscription']['uid']) || empty($payload['data']['subscriber']['uid']))
            mbbx_http_error(400, 'Bad request. Missing subscription or subscriber uid');

        try {
            $id && $this->helper->maybe_migrate_subscriptions(wc_get_order($id));
        } catch (\Exception $e) {
            mbbxs_log('error', "Error migrating subscriptions. Order ID $id", $e->getMessage());
        }

        try {
            $subscription = \MobbexSubscription::get_by_uid($payload['data']['subscription']['uid']);
            $subscriber   = \MobbexSubscriber::get_by_uid($payload['data']['subscriber']['uid']);

            if (!$subscription)
                throw new \Exception('Invalid data. Subscription not found');
    
            if (!$subscriber)
                throw new \Exception('Invalid data. Subscriber not found');
    
            $parent_order = wc_get_order($subscriber->order_id);

            if (!$parent_order)
                throw new \Exception('Invalid data. Order not found');
    
            // Add initial order note
            $parent_order->add_order_note("Received data to process Mobbex webhook. Type of webhook $payload[type]");

            switch ($payload['type']) {
                case 'subscription:registration':
                    $this->process_webhook_registration($payload['data'], $subscriber, $subscription, $parent_order); break;
                case 'subscription:execution':
                    $this->process_webhook_execution($payload['data'], $subscriber, $subscription, $parent_order); break;
                case 'subscription:change_source':
                    break;
                case 'subscription:subscriber:active':
                    break;
                case 'subscription:subscriber:suspended':
                    break;
                default:
                    throw new \Exception("Unsupported webhook type $payload[type]");
            }

            echo 'OK' . MOBBEX_SUBS_VERSION, exit;
        } catch (\Exception $e) {
            if (isset($parent_order) && method_exists($parent_order, 'add_order_note'))
                $parent_order->add_order_note('Error processing webhook: ' . $e->getMessage());

            mbbx_http_error(500, 'Error processing webhook', $e->getMessage());
        }
    }

    /**
     * Process the webhook registration.
     * 
     * @param array $data 
     * @param \MobbexSubscriber $subscriber
     * @param \MobbexSubscription $subscription
     * @param \WC_Order $parent_order
     * 
     * @throws Exception 
     */
    public function process_webhook_registration($data, $subscriber, $subscription, $parent_order)
    {
        $result = isset($data['context']['status']) && $data['context']['status'] === 'success';

        // Save subscriber data
        $subscriber->state         = (int) $result;
        $subscriber->start_date    = $subscription->calculateDates()['current'];
        $subscriber->register_data = json_encode($data);

        // Add order note
        $parent_order->add_order_note(sprintf(
            'Mobbex Subscription UID: %s. Mobbex Subscriber UID: %s. Status: %s',
            $data['subscription']['uid'],
            $data['subscriber']['uid'],
            isset($data['context']['status']) ? $data['context']['status'] : 'unknown'
        ));

        if ($result) {
            $this->helper->update_order_status($parent_order, 'approved', 'Payment approved from webhook');
        } else {
            if ($this->helper->is_order_paid($parent_order))
                throw new \Exception('Parent order already paid moving to failed state on registration');

            $this->helper->update_order_status($parent_order, 'failed', 'Payment denied from webhook');
        }
    }

    /**
     * Process the webhook execution.
     * 
     * @param array $data 
     * @param \MobbexSubscriber $subscriber
     * @param \MobbexSubscription $subscription
     * @param \WC_Order $parent_order
     * 
     * @throws Exception 
     */
    public function process_webhook_execution($data, $subscriber, $subscription, $parent_order)
    {
        $reference = isset($data['payment']['reference']) ? $data['payment']['reference'] : null;

        // If is using WCS, get the renewal order
        if ($this->helper->is_wcs_active()) {
            if ($reference) {
                $reference_parts = explode('_', $reference);

                if (count($reference_parts) < 3)
                    throw new \Exception('Invalid reference format. Expected <subscription_uid>_<subscriber_uid>_<order_id>');

                $renewal_order = wc_get_order($reference_parts[2]);
            } else {
                $wcs_sub = $this->helper->get_wcs_subscription($parent_order->get_id());

                if (!$wcs_sub || !$wcs_sub->get_id())
                    throw new \Exception('WCS subscription not found for execution');

                $renewal_order = wcs_create_renewal_order($wcs_sub);
            }

            if (!$renewal_order || !$renewal_order->get_id())
                throw new \Exception("Renewal order not found for reference $reference");

            if ($this->helper->is_order_paid($renewal_order))
                throw new \Exception('Renewal order already paid');
        }

        // Set scheduled payment attemp as true to regenerate retries on failed
        add_filter('wcs_is_scheduled_payment_attempt', '__return_true');

        // Update order status
        $this->helper->update_order_status(
            $this->helper->is_wcs_active() ? $renewal_order : $parent_order,
            $this->helper::get_state($data['payment']['status']['code']),
            'Updating status from webhook. '. $data['payment']['status']['code']
        );

        // Update execution dates
        $dates = $subscription->calculateDates();
        $subscriber->last_execution = $dates['current'];
        $subscriber->next_execution = $dates['next'];

        // Save subscriber and execution to db
        $subscriber->save(false);
        $subscriber->saveExecution(
            $data, 
            ($this->helper->is_wcs_active() ? $renewal_order : $parent_order)->get_id(),
            $dates['current']
        );
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
            $error = __("Payment failed. Please try updating your payment method and retry the transaction.");
            Mbbxs_Helper::_redirect_to_cart_with_error($error);
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
                $sub_options['test']       = Mbbx_Subs_Product::get_test_mode($product_id);
                $sub_options['signup_fee'] = Mbbx_Subs_Product::get_signup_fee($product_id);
                $sub_options['trial']      = Mbbx_Subs_Product::get_free_trial($product_id)['interval'];
                $sub_options['interval']   = implode(Mbbx_Subs_Product::get_charge_interval($product_id));
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
     * @param WC_Order $order
     * 
     * @return bool Result of charge execution.
     */
    public function scheduled_subscription_payment($total, $order)
    {
        try {
            if (!$this->helper->is_wcs_active())
                throw new \Exception('WooCommerce Subscriptions integration is not active');

            if (!$order || !$order->get_id())
                throw new \Exception('Invalid order data');

            if (empty($total))
                throw new \Exception('Invalid total amount for order' . $order->get_id());

            if ($this->helper->is_order_paid($order))
                throw new \Exception('Order already paid');

            // Mark as processing
            $order->add_order_note("Processing scheduled payment for $ $total. Order ID " . $order->get_id());

            // Get wcs subscription
            $wcs_sub = $this->helper->get_wcs_subscription($order->get_id());

            if (empty($wcs_sub))
                throw new \Exception('No subscriptions found for order' . $order->get_id());

            try {
                $this->helper->maybe_migrate_subscriptions($wcs_sub->order);
            } catch (\Exception $e) {
                $order->add_order_note('Error migrating subscriptions: ' . $e->getMessage());
                mbbxs_log('error', 'Error migrating subscriptions: ' . $e->getMessage());
            }

            // Get subscriber
            $subscriber = new \MobbexSubscriber($wcs_sub->order->get_id());

            if (empty($subscriber->uid) || empty($subscriber->subscription_uid))
                throw new \Exception('Invalid subscriber data for order' . $wcs_sub->order->get_id());

            $this->execute_scheduled_charge($subscriber, $order, $total);
        } catch (\Exception $e) {
            mbbxs_log('error', "Charge execution error: " . $e->getMessage());

            // We cant update status if order is invalid
            if ($e->getMessage() == 'Invalid order data')
                return;

            $this->helper->update_order_status($order, 'failed', 'Charge execution error: ' . $e->getMessage());
        }
    }

    /**
     * Process scheduled payment charge.
     * 
     * @param \MobbexSubscriber $subscriber
     * @param WC_Order $order
     * @param float $total
     * 
     * @throws Exception 
     */
    public function execute_scheduled_charge($subscriber, $order, $total)
    {
        // Generate and log reference
        $reference = implode('_', [$subscriber->subscription_uid, $subscriber->uid, $order->get_id()]);
        $order->add_order_note("Executing charge with reference $reference");

        // Default values for the new order status
        $update = [
            'status'  => 'on-hold',
            'message' => 'Awaiting webhook for ' . $reference,
        ];

        $res = $subscriber->execute_charge($reference, $total);

        // If has an error
        if (empty($res['result'])) {
            $code = isset($res['code']) ? $res['code'] : 'NOCODE';

            if ($code == 'SUBSCRIPTIONS:EXECUTION_ALREADY_IN_PROGRESS') {
                $execution = $subscriber->search_execution($reference);
                $status    = isset($execution['status']) ? $execution['status'] : 'unknown';

                if ($status == 'failed') {
                    $subscriber->retry_charge($execution['uid']);

                    $update = [
                        'status'  => 'on-hold',
                        'message' => "Already in progress. Awaiting webhook for $reference (charge retried)",
                        ];
                    } else if ($status == 'approved') {
                        $update = [
                            'status'  => 'approved',
                            'message' => "Already in progress. Charge approved for $reference",
                        ];
                    } else {
                        $update = [
                            'status'  => 'on-hold',
                            'message' => "Already in progress. Awaiting webhook for $reference ($status)",
                        ];
                                    }
            } else {
                $update = [
                    'status'  => 'failed',
                    'message' => sprintf(
                        "Mobbex request error #$code: %s %s",
                        isset($res['error']) ? $res['error'] : 'NOERROR',
                        isset($res['status_message']) ? $res['status_message'] : 'NOMESSAGE'
                )];
            }
        }

        $this->helper->update_order_status($order, $update['status'], "Charge execution result: $update[message]");
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
        
        if(!$subscription || !$subscription->get_parent()){
            mbbxs_log('error', 'Hook > update_subscriber_state. No subscription or parent.', ['subscription' => $subscription]);
            return;
        }

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
