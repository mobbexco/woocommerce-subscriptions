<?php
namespace Model;
class Helper
{
    public static $config;

    public static $periods = [
        'd' => 'day',
        'm' => 'month',
        'y' => 'year'
    ];

    public function __construct()
    {
        self::$config = new \Mobbex\WP\Checkout\Model\Config;
    }

    public static function is_wcs_active()
    {
        return (!empty(self::$config->integration) && self::$config->integration === 'wcs' && get_option('woocommerce_subscriptions_is_active'));
    }

    public function get_api_endpoint($endpoint)
    {
        $query = [
            'mobbex_token' => \Mobbex\Repository::generateToken(),
            'platform' => "woocommerce",
            "version" => MOBBEX_SUBS_VERSION,
        ];

        $query['wc-api'] = $endpoint;

        return add_query_arg($query, home_url('/'));
    }

    /**
	 * Get payment state from Mobbex status code.
     * 
     * @param int|string $status
     * 
     * @return string "approved" | "on-hold" | "cancelled"
	 */
    public static function get_state($status)
    {
        if ($status == 2 || $status == 3 || $status == 100 || $status == 201) {
            return 'on-hold';
        } else if ($status == 4 || $status >= 200 && $status < 300) {
            return 'approved';
        } else if ($status >= 300 && $status < 400) {
            return 'processing';
        } else {
            return 'cancelled';
        }
	}

    public static function calculate_checkout_total($checkout_total, $subscription)
    {
        return $checkout_total - $subscription->total - ($subscription->signup_fee ?? 0);
    }

    /**
     * Display sign up fee on product price
     * 
     * @param string $price_html
     * @param WC_Product $product
     * 
     * @return string $sign_up_fee
     */
    public function display_sign_up_fee_on_price($price_html, $product)
    {
        // Sometimes the hook gets an array type product and avoid non subscription products
        if (!is_object($product) || !$this->is_subscription($product->get_id()) )
            return $price_html;

        // Set sign up price
        $sign_up_price = $this->config->get_product_subscription_signup_fee($product->get_id());

        return $sign_up_price ? $price_html .= __(" /month and a $$sign_up_price sign-up fee") : $price_html;
    }

    /**
     * Maybe add product subscriptions sign-up fee 
     * 
     * @param object $checkout used to get items and total
     * 
     * @return int|string total cleared
     */
    // public function maybe_add_signup_fee($items)
    // { 
    //     $signup_fee_totals = 0;
        
    //     foreach ($items as $item)
    //         if($item['type'] == 'subscription'){
    //             $subscription       = \Mobbex\Repository::getProductSubscription($item['reference'], true);
    //             $signup_fee_totals += $subscription['setupFee'];
    //         }

    //     $checkout->total = $checkout->total - $signup_fee_totals;
    // }
}