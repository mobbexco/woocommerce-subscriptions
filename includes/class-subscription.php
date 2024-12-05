<?php

class MobbexSubscription extends \Mobbex\Model {

    /** @var MobbexApi */
    public $api;

    public $product_id;
    public $uid;
    public $type;
    public $state;
    public $interval;
    public $name;
    public $description;
    public $total;
    public $limit;
    public $free_trial;
    public $signup_fee;
    public $result;
    public $helper;

    public $table       = 'mobbex_subscription';
    public $primary_key = 'product_id';
    
    public $periods = [
        'd' => 'day',
        'm' => 'month',
        'y' => 'year',
    ];

    public $fillable = [
        'product_id',
        'reference',
        'total',
        'signup_fee',
        'type',
        'name',
        'description',
        'interval',
        'free_trial',
        'limit' 
    ];

    /**
     * Build a Subscription from product id.
     * 
     * @param object $api
     * @param int|null $productId It can be an order id for old subscriptions support
     * @param string|null $reference
     * @param int|float|null $total Amount to charge.
     * @param int|float|null $setupFee Different initial amount.
     * @param string|null $type "manual" | "dynamic"
     * @param string|null $name
     * @param string|null $description
     * @param string|null $interval Interval between executions.
     * @param int|null $limit Maximum number of executions.
     * @param int|null $freeTrial Number of free periods.
     * 
     */
    public function __construct(
        $product_id  = null,
        $reference   = null,
        $total       = null,
        $signup_fee  = null,
        $type        = null,
        $name        = null,
        $description = null,
        $interval    = null,
        $free_trial  = null,
        $limit       = null
    ) {
        $this->helper = new \Mbbxs_Helper();
        $this->api    = new \MobbexApi($this->helper->api_key, $this->helper->access_token);

        $this->return_url  = $this->helper->get_api_endpoint('mobbex_subs_return_url');
        $this->webhook_url = $this->helper->get_api_endpoint('mobbex_subs_webhook');

        parent::__construct(...func_get_args());
    }

    /**
     * Create a Subscription using Mobbex API.
     * 
     * @return array|null response data if created correctly.
     */
    public function create()
    {
        $features = [];
        
        if(get_option('send_subscriber_email') === 'yes')
            array_push($features, 'no_email');
        if(!$this->free_trial)
            array_push($features, 'charge_on_first_source');

        $data = [
            'uri'    => 'subscriptions/' . $this->uid,
            'method' => 'POST',
            'body'   => [
                'reference'   => $this->reference,
                'total'       => $this->total,
                'setupFee'    => $this->get_signup_fee(),
                'currency'    => 'ARS',
                'type'        => $this->type,
                'name'        => $this->name,
                'description' => $this->name,
                'interval'    => $this->interval ?: '',
                'trial'       => $this->free_trial ?: '',
                'limit'       => $this->limit ?: 0,
                'return_url'  => $this->return_url,
                'webhook'     => $this->webhook_url,
                'features'    => $features,
                'test'        => $this->is_test_subscription(),
                'options'     => [
                    'platform' => $this->get_platform_data(),
                    'embed'    => get_option('send_subscriber_email') === 'yes',
                ],
            ]
        ];

        try {
            mbbxs_log('debug', 'MobbexSubscription > create()', ['data' => $data]);
            return $this->api->request($data);
        } catch (\Exception $e) {
            $this->logger->debug('Mobbex Subscription Create/Update Error: ' . $e->getMessage(), [], true);
        }
    }

    /**
     * Save/update data to db creating subscription from Mobbex API.
     * 
     * @return bool True if saved correctly.
     */
    public function save($arg = null)
    {
        $response = $this->create();

        // Try to save uid
        if (!empty($response['uid']))
            $this->uid = $response['uid'];

        $data = [
            'product_id'  => $this->product_id ?: '',
            'uid'         => $this->uid ?: '',
            'type'        => $this->type ?: '',
            'state'       => $this->state ?: 200,
            'interval'    => $this->interval ?: '',
            'name'        => $this->name ?: '',
            'description' => $this->description ?: '',
            'total'       => $this->total ?: '',
            'limit'       => $this->limit ?: '',
            'free_trial'  => $this->free_trial ?: '',
            'signup_fee'  => $this->signup_fee ?: '',
        ];

        return $this->uid && parent::save($data);
    }

    /**
     * Calculate execution dates from Subscription interval.
     * 
     * @return string[]
     */
    public function calculateDates()
    {
        $interval = preg_replace('/[^0-9]/', '', (string) $this->interval) ?: 1;
        $period   = $this->periods[preg_replace('/[0-9]/', '', (string) $this->interval) ?: 'm'];

        return [
            'current' => date('Y-m-d H:i:s'),
            'next'    => date('Y-m-d H:i:s', strtotime("+ $interval $period"))
        ];
    }

    /**
     * Returns the name & version of the plugins involved in the subscription.
     */
    public function get_platform_data()
    {
        global $wp_version;

        $platform = [
            [
                'name'    => 'WordPress',
                'version' => $wp_version,
            ],
            [
                'name'    => 'Woocommerce',
                'version' => defined('WC_VERSION') ? WC_VERSION : '',
            ],
            [
                'name'    => 'Mobbex Subscriptions for Woocommerce',
                'version' => MOBBEX_SUBS_VERSION,
            ],
        ];

        //If integrated with woocommerces subs add plugin version to body
        if (isset($this->helper->integration) &&  $this->helper->integration === "wcs") {
            $wcs_data = get_plugin_data(WP_PLUGIN_DIR . '/woocommerce-subscriptions/woocommerce-subscriptions.php');
            $body['options']['platform'][] = ['name' => 'Woocommerce Subscriptions', 'version' => $wcs_data['Version']];
        }

        return $platform;
    }

    /**
     * Get the correct signup value according the case
     * 
     * @return int signup_fee value
     */
    public function get_signup_fee()
    {
        if ($this->signup_fee != 0)
            return $this->signup_fee;

        if ($this->type == 'manual')
            return $this->total;

        return 0;
    }

    /**
     * Get a Subscription using UID.
     * 
     * @param string $uid
     * 
     * @return \MobbexSubscription|null
     */
    public static function get_by_uid($uid)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscription" . " WHERE uid='$uid'", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscription($result[0]['product_id']) : null;
    }

    public static function is_stored($id)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscription" . " WHERE product_id='$id'", 'ARRAY_A');

        return !empty($result[0]);
    }

    /**
     * Check if the subscription was configured in test mode
     * 
     * @return bool is test
     */
    public function is_test_subscription()
    {
        if (isset($this->helper->integration) && $this->helper->integration === "wcs")
            return (get_post_meta($this->product_id, 'mobbex_subscription_test_mode', true) == 'yes');
        else
            return (get_post_meta($this->product_id, 'mbbxs_test_mode', true) == 'yes');
    }
}