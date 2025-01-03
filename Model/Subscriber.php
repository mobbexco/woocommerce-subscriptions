<?php
namespace MobbexSubscription;

class Subscriber extends \MobbexSubscription\Model
{
    /** @var \Mobbex\Api */
    public $api;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public $logger;

    /** @var \Mobbex\Repository */
    public $repository;

    public $uid;
    public $state;
    public $test;
    public $name;
    public $email;
    public $phone;
    public $helper;
    public $result;
    public $order_id;
    public $reference;
    public $addresses;
    public $source_url;
    public $start_date;
    public $customer_id;
    public $control_url;
    public $register_data;
    public $identification;
    public $last_execution;
    public $next_execution;
    public $subscription_uid;

    public $primary_key   = 'order_id';
    public $array_columns = ['register_data'];
    public $table         = 'mobbex_subscriber';
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
        $this->repository = new \Mobbex\Repository;
        $this->helper     = new \MobbexSubscription\Helper();
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
        $subscription = (new \MobbexSubscription\Subscription)->get_by_uid($this->subscription_uid); // Aca hay que revisar
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
                $subscription->total,
            );
            $this->logger->log(
                'debug', 
                "MobbexSubscriber\Subscriber Created. subscriber_uid: $subscriber->uid"
            );

            return $subscriber->response;
        } catch (\Exception $e) {
            $this->logger->log(
                'error', 
                "MobbexSubscriber\Subscriber Create/Update Error: {$e->getMessage()}", 
                [$this, $dates, $order]
                );
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
                'city'         => $object->$city(),
                'state'        => $object->$state(),
                'zipCode'      => $object->$postcode(),
                'streetNotes'  => $object->$address_2(),
                'country'      => $this->repository->convertCountryCode($object->$country()),
                'street'       => trim(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->$address_1()))),
                'streetNumber' => str_replace(preg_replace('/(\D{0})+(\d*)+$/', '', trim($object->$address_1())), '', trim($object->$address_1())),
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
    public function save_execution($webhookData, $order_id, $date)
    {
        global $wpdb;

        $data = [
            'date'             => $date,
            'order_id'         => $order_id,
            'subscriber_uid'   => $this->uid,
            'subscription_uid' => $this->subscription_uid,
            'data'             => json_encode($webhookData),
            'total'            => $webhookData['payment']['total'],
            'status'           => $webhookData['payment']['status']['code'],
            'uid'              => $webhookData['subscriptions'][0]['execution'],
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
            $this->test        = isset($this->result['test'])          ? $this->result['test']          : $this->test;
            $this->source_url  = isset($this->result['sourceUrl'])     ? $this->result['sourceUrl']     : $this->source_url;
            $this->control_url = isset($this->result['subscriberUrl']) ? $this->result['subscriberUrl'] : $this->control_url;
        }

        $data = [
            'uid'              => $this->uid ?: '',
            'test'             => $this->test ?: '',
            'name'             => $this->name ?: '',
            'state'            => $this->state ?: '',
            'email'            => $this->email ?: '',
            'phone'            => $this->phone ?: '',
            'order_id'         => $this->order_id ?: '',
            'source_url'       => $this->source_url ?: '',
            'start_date'       => $this->start_date ?: '',
            'control_url'      => $this->control_url ?: '',
            'customer_id'      => $this->customer_id ?: '',
            'last_execution'   => $this->last_execution ?: '',
            'next_execution'   => $this->next_execution ?: '',
            'identification'   => $this->identification ?: '',
            'subscription_uid' => $this->subscription_uid ?: '',
            'register_data'    => $this->register_data ? json_encode($this->register_data) : '',
        ];
        
        $this->logger->log('debug', 'MobbexSubscriber\Subscriber > save - data: ', ['data' => $data]);
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
        $this->logger->log(
            'debug', 
            "Execute Charge. Init. Total $total. $this->subscription_uid $this->uid"
        );

        $data = [
            'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/execution",
            'method' => 'POST',
            'raw'    => true,
            'body'   => [
                'reference' => $reference,
                'total'     => (float) $total,
                'test'      => (bool) $this->test,
            ]
        ];
        $this->logger->log(
            'debug', 
            'MobbexSubscriber\Subscriber > execute_charge - data: ', 
            ['data' => $data]
        );

        try {
            return $this->api::request($data);
        } catch (\Exception $e) {
            $this->logger->log(
                "debug", 
                "MobbexSubscriber\Subscriber > execute_charge Error: {$e->getMessage()}",
            );
        }
    }

    /**
     * Get a Subscriber using UID.
     * 
     * @param string $uid
     * 
     * @return \MobbexSubscriber|null
     */
    public function get_by_uid($subscriber_uid, $subscription_uid = null, $order_id = null)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mobbex_subscriber WHERE uid=$subscriber_uid");

        if (empty($result))
            $result = $this->get_subscriber($subscriber_uid, $subscription_uid, $order_id);
        $this->logger->log(
            'debug', 
            "MobbexSubscriber\Subscriber > get_by_uid - error: $wpdb->last_error",
        );

        return $result;
    }

    /**
     * Get a Subscriber from db using product id.
     * 
     * @param string $id
     * @param bool $array
     * 
     * @return array|object \MobbexSubscription\Subscriber|null
     */
    public static function get_by_id($id, $array = true)
    {
        global $wpdb;
        $logger = new \Mobbex\WP\Checkout\Model\Logger;

        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscriber" . " WHERE order_id='$id'", $array ? 'ARRAY_A' : 'OBJECT');

        $logger->log('debug', 'MobbexSubscriber\Subscriber > get_by_id error: ' . $wpdb->last_error, $wpdb->last_error);
        
        if ($result[0] && !$array)
            return new \MobbexSubscription\Subscriber(
                $result[0]->order_id,
                $result[0]->subscription_uid,
                $result[0]->reference,
                $result[0]->name,
                $result[0]->email,
                $result[0]->phone,
                $result[0]->identification,
                $result[0]->customer_id
            );

        return $result[0];

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
        $checkot_helper = new \Mobbex\WP\Checkout\Model\Helper();

        // Checks if plugin is ready
        if (!$checkot_helper->is_extension_ready()) {
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
        try {
            $this->api::request([
                "method" => "POST",
                'uri'    => "subscriptions/$this->subscription_uid/subscriber/$this->uid/action/$action"
            ]);
        } catch (\Exception $e) {
            $this->logger->log(
                'debug', 
                "Mobbex Subscriber Create/Update Error: {$e->getMessage()}",
            );
        }
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
     * Get subscriber data from Mobbex
     * 
     * @param string $subscription_uid
     * @param string $subscriber_uid
     * 
     * @return subscriber
     */
    public function get_subscriber($subscriber_uid, $subscription_uid = null, $order_id = null)
    {
        $this->logger->log(
            'debug', 
            "Mobbex Subscriber > get_subscriber: $subscription_uid / $subscriber_uid",
        );

        if (isset($subscriber_uid, $subscription_uid, $order_id)){
            try {
                $res= $this->api::request([
                    "method" => "GET",
                    'uri'    => "subscriptions/$subscription_uid/subscriber/$subscriber_uid"
                ]);
            } catch (\Exception $e) {
                $this->logger->log(
                    'debug', 
                    "Mobbex Subscriber > get_subscriber: {$e->getMessage()}", 
                    ['exception' => $e]
                );
            };
        
            $subscriber = new \MobbexSubscription\Subscriber(
                $order_id,
                $subscription_uid,
                $res['subscriber']['reference'],
                $res['subscriber']['customerData']['name'],
                $res['subscriber']['customerData']['email'],
                $res['subscriber']['customerData']['phone'],
                $res['subscriber']['customerData']['identification'],
                $res['subscriber']['customerData']['uid']
            );

            $subscriber->save();
            return $subscriber;
        }
    }
}