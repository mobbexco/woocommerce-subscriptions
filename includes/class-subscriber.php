<?php

class MobbexSubscriber extends \Mobbex\Model
{
    /** @var \Mobbex\Api */
    public $api;

    public $order_id;
    public $uid;
    public $subscription_uid;
    public $state;
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

    /**
     * Build a Subscriber from cart id.
     * 
     * @param int|null $cartId
     * @param string|null $subscriptionUid
     * @param bool|null $test Enable test mode for this subscriber.
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
     * Create a Subscriber using Mobbex API.
     * 
     * @return string|null UID if created correctly.
     */
    public function create()
    {

        $subscription = $this->helper->getSubscriptionByUid($this->subscription_uid);
        $dates        = $subscription->calculateDates();
        $order        = wc_get_order($this->order_id);

        $data = [
            'uri'    => 'subscriptions/' . $this->subscription_uid . '/subscriber/' . $this->uid,
            'method' => 'POST',
            'body'   => [
                'reference' => (string) $this->reference,
                'test'      => ($this->helper->test_mode === 'yes'),
                'total'     => $this->get_total($order),
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
        ];

        try {
            return $this->api->request($data);
        } catch (\Exception $e) {
            $this->logger->debug('Mobbex Subscriber Create/Update Error: ' . $e->getMessage(), [], true);
        }
    }

    /**
     * Get order total with discounts
     * 
     * @param WC_Order|WC_Abstract_Order $order
     * 
     * @return float $total
     */
    public function get_total($order)
    {
        if ($this->helper->has_subscription($this->order_id)) {
            // Get total
            $total = $order->get_total() - $this->get_subscription_discount();
        } else if ($this->helper->is_wcs_active() && wcs_order_contains_subscription($this->order_id)) {
            // Get wcs subscription
            $subscriptions = wcs_get_subscriptions_for_order($this->order_id, ['order_type' => 'any']);
            $wcs_sub       = end($subscriptions);

            $total = $wcs_sub->get_total() - $this->get_subscription_discount();
        }

        return $total ?: $order->get_total();
    }

    /**
     * Gets the discount value/s and calculates the sum of these
     * 
     * @return int $discount total coupon discount
     * 
     */
    public function get_subscription_discount()
    {
        $discount = WC()->cart->get_coupon_discount_totals();

        // If there is more than one coupon, calculate the sum of their discounts
        return $discount ? array_sum($discount) : 0;
    }

    /**
     * Set address data.
     * 
     * @param Class $object Order class.
     * 
     */
    public function get_addresses($object)
    {
        foreach (['billing', 'shipping'] as $type) {

            foreach (['address_1', 'address_2', 'city', 'state', 'postcode', 'country'] as $method)
                ${$method} = "get_" . $type . "_" . $method;

            $this->addresses[] = [
                'type'         => $type,
                'country'      => $this->convert_country_code($object->$country()),
                'state'        => $object->$state(),
                'city'         => $object->$city(),
                'zipCode'      => $object->$postcode(),
                'street'       => trim(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->$address_1()))),
                'streetNumber' => str_replace(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->$address_1())), '', trim($object->$address_1())),
                'streetNotes'  => $object->$address_2()
            ];
        }
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
        
        return $wpdb->insert($wpdb->prefix.'mobbex_execution', $data);
    }

    /**
     * Save/update data to db creating subscriber from Mobbex API.
     * 
     * @return bool True if saved correctly.
     */
    public function save($data = null)
    {
        $this->result = $this->create();

        if ($this->result) {
            $this->uid         = $this->result['uid'];
            $this->source_url  = $this->result['sourceUrl'];
            $this->control_url = $this->result['subscriberUrl'];
        }

        $data = [
            'order_id'         => $this->order_id ?: '',
            'uid'              => $this->uid ?: '',
            'subscription_uid' => $this->subscription_uid ?: '',
            'state'            => $this->state ?: '',
            'test'             => ($this->helper->test_mode === 'yes'),
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
     * @param integer $total
     * @return array|null $response_result
     */
    public function execute_charge($total)
    {
        $data = [
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/execution",
            'method' => 'GET',
            'body'   => [
                'total' => $total,
                'test' => ($this->helper->test_mode === 'yes'),
            ]
        ];

        try {
            return $this->api->request($data);
        } catch (\Exception $e) {
            $this->logger->debug('Mobbex Subscriber Create/Update Error: ' . $e->getMessage(), [], true);
        }
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
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscriber" . " WHERE uid='$uid'", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscriber($result[0]['order_id']) : null;
    }
}