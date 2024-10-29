<?php

class MobbexSubscriber extends \Mobbex\Model
{
    /** @var \Mobbex\Repository */
    public $repository;
    /** @var \Mobbex\Api */
    public $api;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public $logger;

    public $order_id;
    public $subscription_uid;
    public $uid;
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
    public $addresses;

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
        $this->api        = new \Mobbex\Api();
        $this->helper     = new \Mbbxs_Helper();
        $this->repository = new \Mobbex\Repository;
        $this->logger     = new \Mobbex\WP\Checkout\Model\Logger();

        parent::__construct(...func_get_args());
    }

    /**
     * Syncronize Subscriber data on Mobbex.
     * 
     * @return string|null UID if created correctly.
     */
    public function sync()
    {
        $subscription = \MobbexSubscription::get_by_uid($this->subscription_uid); // Aca hay que revisar
        $dates        = $subscription->calculateDates();
        $order        = wc_get_order($this->order_id);

        try {
            $subscriber = new \Mobbex\Modules\Subscriber(
                $this->reference,
                $this->uid,
                $this->subscription_uid,
                $dates['current'],
                [
                    'name'           => (string) $this->name,
                    'email'          => (string) $this->email,
                    'phone'          => (string) $this->phone,
                    'identification' => (string) $this->identification
                ],
                $this->get_addresses($order),
                $this->total,
            );
            return $subscriber->response;
        } catch (\Exception $e) {
            $this->logger->log('error', 'Mobbex Subscriber Create/Update Error: ' . $e->getMessage(), [$this, $dates, $order]);
        }
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
                'country'      => $this->repository->convertCountryCode($object->$country()),
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
     * @param integer $total
     * @return array|null $response_result
     */
    public function execute_charge($total)
    {
        $data = [
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/execution",
            'method' => 'POST',
            'body'   => [
                'total' => (float) $total,
                'test' => ($this->helper->test_mode === 'yes'),
            ]
        ];

        try {
            return $this->api::request($data);
        } catch (\Exception $e) {
            $this->logger->log('debug', 'Mobbex Subscriber Create/Update Error: ' . $e->getMessage(), []);

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

        $action = '';

        // Status must have changed from any status to active or to on-hold
        if ($status === 'cancelled')
            $action = 'suspend';
        elseif ($status === 'active')
            $action = 'activate';
        else
            return;

        // Send endpoint to Mobbex api
        $this->api::request([
            "method" => "POST",
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/action/$action"
        ]);

    }
}