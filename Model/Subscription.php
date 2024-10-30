<?php
namespace MobbexSubscription;

class Subscription extends \MobbexSubscription\Model {

    /** @var \MobbexSubscription\Helper */
    public $helper;

    /** @var \Mobbex\Api */
    public $api;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public $logger;

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
    public $features;
    public $return_url;
    public $webhook_url;
    public $checkout_helper;

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
        $this->api             = new \Mobbex\Api();
        $this->helper          = new \MobbexSubscription\Helper();
        $this->logger          = new \Mobbex\WP\Checkout\Model\Logger();
        $this->checkout_helper = new \Mobbex\WP\Checkout\Model\Helper;


        $this->return_url  =  $this->checkout_helper->get_api_endpoint('mobbex_subs_return_url');
        $this->webhook_url =  $this->checkout_helper->get_api_endpoint('mobbex_subs_webhook');

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

        try {
            $subscription = new \Mobbex\Modules\Subscription(
                $this->product_id,
                $this->uid,
                $this->type,
                $this->return_url,
                $this->webhook_url,
                (float) $this->total,
                $this->name,
                $this->description,
                $this->interval,
                $features,
                $this->free_trial
            );
            error_log('Subscription: ' . "\n" . json_encode($subscription, JSON_PRETTY_PRINT) . "\n", 3, 'log.log');
            return $subscription->response;
        } catch (\Exception $e) {
            $this->logger->log('error', 'Mobbex Subscriber Create/Update Error: ' . $e->getMessage(), $this);
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
        if ($this->helper->integration === 'wcs') {
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
     * @return \Mobbex\Subscription|null
     */
    public static function get_by_uid($uid)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscription" . " WHERE uid='$uid'", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscription\Subscription($result[0]['product_id']) : null;
    }

    /**
     * Get a Subscription using product id.
     * 
     * @param string $id
     * 
     * @return \Mobbex\Subscription|null
     */
    public static function get_by_id($id)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscription" . " WHERE product_id='$id'", 'ARRAY_A');

        return !empty($result[0]) ? new \MobbexSubscription\Subscription($result[0]['product_id']) : null;
    }

    public static function is_stored($id)
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "mobbex_subscription" . " WHERE product_id='$id'", 'ARRAY_A');

        return !empty($result[0]);
    }
    
    /**
     * Modify Subscription parameters using Mobbex API.
     * 
     * @param string|int $subscription_uid
     * @param array $params Parameters to modify
     * 
     * @return bool $result
     */
    public function modify_subscription($subscription_uid, $params)
    {
        if (empty($subscription_uid) || empty($params)) {
            throw new Exception(__('Empty Subscription UID or params', 'mobbex-subs-for-woocommerce'));
        }

        // Request data 
        $data = [
            'method' => 'POST',
            'body'   => $params,
            'uri'    => "subscriptions/{$subscription_uid}",
        ];

        $response = $this->api::request($data);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result']))
                return true;
        }

        throw new Exception(__('An error occurred in the execution', 'mobbex-subs-for-woocommerce'));
    }

    /**
     * Creates/Update a Mobbex Subscription & return Subscription class
     * 
     * @param array $sub_options
     * 
     * @return \Mobbex\Subscription|null
     */
    public static function create_mobbex_subscription($sub_options)
    {
        $subscription = new \MobbexSubscription\Subscription(
            $sub_options['post_id'],
            $sub_options['reference'],
            $sub_options['price'],
            $sub_options['setup_fee'],
            $sub_options['type'],
            $sub_options['name'],
            $sub_options['name'],
            $sub_options['interval'],
            $sub_options['trial'],
            0,
        );

        if(!empty($subscription)){
            //Save Subscription 
            $subscription->save();
            return $subscription;
        }

        return null;
    }

    public static function is_subscription($product_id)
    {
        $subscription = self::get_by_id($product_id);
        return isset($subscription);
    }

    /**
     * Maybe add product subscriptions sign-up fee 
     * 
     * @param object $checkout used to get items and total
     * 
     * @return int|string total cleared
     */
    public function maybe_add_signup_fee($subscription, $checkout)
    {
        $subscription       = \Mobbex\Repository::getProductSubscription($item['reference'], true);
        $signup_fee_totals += $subscription['setupFee'];

        $checkout->total = $checkout->total - $signup_fee_totals;
    }
}