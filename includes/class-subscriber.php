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

    public $table    = 'mobbex_subscribers';
    public $primary  = 'order_id';
    public $fillable = [
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
                'total'     => $order->get_total(),
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
            'order_id'         => $this->order_id,
            'uid'              => $this->uid,
            'subscription_uid' => $this->subscription_uid,
            'state'            => $this->state ?: '',
            'test'             => ($this->helper->test_mode === 'yes'),
            'name'             => $this->name,
            'email'            => $this->email,
            'phone'            => $this->phone,
            'identification'   => $this->identification,
            'customer_id'      => $this->customer_id ?: '',
            'source_url'       => $this->source_url,
            'control_url'      => $this->control_url,
            'register_data'    => $this->register_data ?: '',
            'start_date'       => $this->start_date ?: '',
            'last_execution'   => $this->last_execution ?: '',
            'next_execution'   => $this->next_execution ?: ''
        ];
        
        return $this->uid && parent::save($data);
    }
}