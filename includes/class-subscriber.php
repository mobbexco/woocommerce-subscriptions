<?php

class MobbexSubscriber extends \Mobbex\Model
{
    /** @var \MobbexApi */
    public $api;

    /** @var \Mbbxs_Helper */
    public $helper;

    /** @var \Mbbxs_Logger */
    public $logger;

    public $order_id;
    public $subscription_uid;
    public $uid;
    public $state;
    public $reference;
    public $test;
    public $name;
    public $email;
    public $phone;
    public $identification;
    public $customer_id;
    public $source_url;
    public $control_url;
    public $register_data;
    public $start_date;
    public $last_execution;
    public $next_execution;
    public $result;

    public $table         = 'mobbex_subscriber';
    public $primary_key   = 'order_id';
    public $array_columns = ['register_data'];
    public $fillable      = [
        'order_id',
        'subscription_uid',
        'reference',
        'name',
        'email',
        'phone',
        'identification',
        'customer_id'
    ];
    
    public $total;

    /**
     * Build a Subscriber from cart id.
     * 
     * @param string|null $order_id
     * @param string|null $subscriptionUid
     * @param string|null $reference
     * @param string|null $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $identification Tax-ID or DNI of the customer.
     * @param int|null $customerId
     */
    public function __construct(
        $order_id         = null,
        $subscription_uid = null,
        $reference        = null,
        $name             = null,
        $email            = null,
        $phone            = null,
        $identification   = null,
        $customer_id      = null
    ) {
        $this->helper = new \Mbbxs_Helper();
        $this->logger = new \Mbbxs_Logger;
        $this->api    = new \MobbexApi($this->helper->api_key, $this->helper->access_token);

        parent::__construct(...func_get_args());
    }

    /**
     * Syncronize Subscriber data on Mobbex.
     * 
     * @return string|null UID if created correctly.
     */
    public function sync()
    {
        $subscription = \MobbexSubscription::get_by_uid($this->subscription_uid);
        $dates        = $subscription->calculateDates();
        $order        = wc_get_order($this->order_id);

        try {
            // Try to search in mobbex
            if (!$this->uid) {
                $existing = $this->search_subscriber($this->reference);

                if (isset($existing['uid'])) {
                    $this->uid = $existing['uid'];

                    // If the subscriber has an active source, throw an error
                    if ($existing['activeSource'])
                        throw new \Exception('Subscriber already has an active source');

                    // If the subscription is not active, activate it
                    if ($existing['status'] !== 'active')
                        $this->update_status('active');
                }
            }

            return $this->api->request([
                'uri'    => 'subscriptions/' . $this->subscription_uid . '/subscriber/' . $this->uid,
                'method' => 'POST',
                'body'   => [
                    'reference' => (string) $this->reference,
                    'test'      => ($this->helper->test_mode === 'yes'),
                    'total'     => $this->get_subscription_total($order, $subscription),
                    'addresses' => $this->get_addresses($order),
                    'startDate' => [
                        'day'   => date('d', strtotime($dates['current'])),
                        'month' => date('m', strtotime($dates['current'])),
                        'year'  => date('Y', strtotime($dates['current'])),
                    ],
                    'customer'  => [
                        'name'           => $this->name,
                        'email'          => $this->email,
                        'phone'          => $this->phone,
                        'identification' => $this->identification,
                        'customer_id'    => $this->customer_id
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->debug('Mobbex Subscriber Create/Update Error: ' . $e->getMessage(), [], true);
        }
    }

    /**
     * Get address data from woocommerce order or cart.
     * 
     * @param WC_Order|WC_Cart $object Order class.
     * 
     * @return array
     */
    public function get_addresses($object)
    {
        return [
            [
                'type'         => 'shipping',
                'country'      => $this->convert_country_code($object->get_shipping_country()),
                'state'        => $object->get_shipping_state(),
                'city'         => $object->get_shipping_city(),
                'zipCode'      => $object->get_shipping_postcode(),
                'street'       => trim(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->get_shipping_address_1()))),
                'streetNumber' => str_replace(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->get_shipping_address_1())), '', trim($object->get_shipping_address_1())),
                'streetNotes'  => $object->get_shipping_address_2()
            ],
            [
                'type'         => 'billing',
                'country'      => $this->convert_country_code($object->get_billing_country()),
                'state'        => $object->get_billing_state(),
                'city'         => $object->get_billing_city(),
                'zipCode'      => $object->get_billing_postcode(),
                'street'       => trim(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->get_billing_address_1()))),
                'streetNumber' => str_replace(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->get_billing_address_1())), '', trim($object->get_billing_address_1())),
                'streetNotes'  => $object->get_billing_address_2()
            ]
        ];
    }

    /**
     * Converts the WooCommerce country codes to 3-letter ISO codes.
     * 
     * @param string $code 2-Letter ISO code.
     * 
     * @return string|null
     */
    public function convert_country_code($code)
    {
        $countries = include ('iso-3166.php') ?: [];

        return isset($countries[$code]) ? $countries[$code] : null;
    }

    /**
     * Save execution data in db.
     * 
     * @param array $webhookData
     * @param string $order_id
     * @param string $string
     * 
     * @return bool True if saved correctly.
     */
    public function saveExecution($webhookData, $order_id, $date)
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $data = [
            'uid'              => $webhookData['execution']['uid'],
            'order_id'         => $order_id,
            'subscription_uid' => $this->subscription_uid,
            'subscriber_uid'   => $this->uid,
            'status'           => $webhookData['payment']['status']['code'],
            'total'            => $webhookData['payment']['total'],
            'date'             => $date,
            'data'             => json_encode($webhookData)
        ];

        $res = $wpdb->replace($wpdb->prefix.'mobbex_execution', $data);

        if ($wpdb->last_error)
            throw new \Exception(__('Error saving execution data: ', 'mobbex-subs-for-woocommerce') . $wpdb->last_error);

        return $res;
    }

    /**
     * Save data to db and sync with Mobbex (optional).
     * 
     * @param bool $sync Synchronize with Mobbex.
     * 
     * @return bool True if saved correctly.
     */
    public function save($sync = true)
    {
        if ($sync) {
            $this->result = $this->sync();

            $this->uid         = isset($this->result['uid'])           ? $this->result['uid']           : $this->uid;
            $this->source_url  = isset($this->result['sourceUrl'])     ? $this->result['sourceUrl']     : $this->source_url;
            $this->control_url = isset($this->result['subscriberUrl']) ? $this->result['subscriberUrl'] : $this->control_url;
            $this->test        = isset($this->result['test'])          ? $this->result['test']          : $this->test;
        }

        $data = [
            'order_id'         => $this->order_id ?: '',
            'uid'              => $this->uid ?: '',
            'subscription_uid' => $this->subscription_uid ?: '',
            'state'            => $this->state ?: '',
            'name'             => $this->name ?: '',
            'email'            => $this->email ?: '',
            'phone'            => $this->phone ?: '',
            'identification'   => $this->identification ?: '',
            'customer_id'      => $this->customer_id ?: '',
            'source_url'       => $this->source_url ?: '',
            'control_url'      => $this->control_url ?: '',
            'register_data'    => $this->register_data ? json_encode($this->register_data) : '',
            'start_date'       => $this->start_date ?: '',
            'last_execution'   => $this->last_execution ?: '',
            'next_execution'   => $this->next_execution ?: ''
        ];
        
        return $this->uid && parent::save($data);
    }

    /**
     * Execute subscription charge manually using Mobbex API.
     * 
     * @param string $reference
     * @param int|float $total
     * 
     * @return array|null $response_result
     */
    public function execute_charge($reference, $total)
    {
        $data = [
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/execution",
            'method' => 'POST',
            'raw'    => true,
            'body'   => [
                'total' => (float) $total,
                'reference' => $reference,
                'test' => $this->helper->test_mode === 'yes',
            ]
        ];

        return $this->api->request($data);
    }

    /**
     * Retry a charge using Mobbex API.
     * 
     * @param string $eid
     * 
     * @return bool|null
     */
    public function retry_charge($eid)
    {
        return $this->api->request([
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/execution/$eid/action/retry",
            'method' => 'GET',
        ]);
    }

    /**
     * Get a Subscriber using UID.
     * 
     * @param string $uid
     * 
     * @return \MobbexSubscriber|null
     */
    public static function get_by_uid($uid)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscriber" . " WHERE uid='$uid' ORDER BY order_id desc", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscriber($result[0]['order_id']) : null;
    }

    public static function is_stored($id)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscriber" . " WHERE order_id='$id'", 'ARRAY_A');

        return !empty($result[0]);
    }

    /**
     * Update subscription state of a subscriber in Mobbex console
     * 
     * @param string $status subscription order status
     * 
     * @return bool $response api response
     * 
     */
    public function update_status($status)
    {
        // Checks if plugin is ready
        if (!$this->helper->is_ready()) {
            throw new Exception(__('Plugin is not ready', 'mobbex-subs-for-woocommerce'));
        }

        // Checks that params are not empty
        if (empty($this->subscription_uid) || empty($this->uid) || empty($status)) {
            throw new Exception(__(
                'Empty Subscription UID, Subscriber UID or Subscription status',
                'mobbex-subs-for-woocommerce'
            ));
        }

        $actions = [
            'active'    => 'activate',
            'cancelled' => 'suspend',
        ];

        if (empty($actions[$status]))
            return;

        // Send endpoint to Mobbex api
        return $this->api->request([
            "method" => "POST",
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/action/{$actions[$status]}"
        ]);
    }

    /**
     * Get correct subscriptions total
     * 
     * @return total
     */
    public function get_subscription_total($order, $subscription)
    {
        // Just to avoid charging a duplicate sign up fee
        return $subscription->signup_fee ? $order->get_total() - $subscription->signup_fee : $order->get_total();
    }

    /**
     * Search subscriber in Mobbex
     * 
     * @param string $search
     * 
     * @return array|null
     */
    public function search_subscriber($search)
    {
        $res = $this->api->request([
            'uri'    => "subscriptions/$this->subscription_uid/subscriber?page=0&search=$search",
            'method' => 'GET'
        ]);

        return empty($res['docs'][0]) ? null : $res['docs'][0];
    }

    /**
     * Search coupon in Mobbex
     * 
     * @param string $reference
     * 
     * @return array|null
     */
    public function search_execution($reference)
    {
        $res = $this->api->request([
            'url'    => "https://api.mobbex.com/p/subscriptions/$this->subscription_uid/subscriber/$this->uid",
            'method' => 'GET'
        ]);

        if (empty($res['subscriber']['executions']) || !is_array($res['subscriber']['executions']))
            return null;

        return array_filter($res['subscriber']['executions'], function ($execution) use ($reference) {
            return $execution['reference'] === $reference;
        });
    }
}