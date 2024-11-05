<?php
namespace MobbexSubscription;
class Helper
{
    public static $config;
    public static $logger;

    public static $periods = [
        'd' => 'day',
        'm' => 'month',
        'y' => 'year'
    ];

    public function __construct()
    {
        self::$config = new \Mobbex\WP\Checkout\Model\Config;
        self::$logger = new \Mobbex\WP\Checkout\Model\Logger;
    }

    public static function is_wcs_active()
    {
        return (!empty(self::$config->integration) && self::$config->integration === 'wcs' && get_option('woocommerce_subscriptions_is_active'));
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

    public static function calculate_checkout_total($subscription)
    {
        return $subscription->total - ($subscription->signup_fee ?? 0);
    }

    /**
     * Display sign up fee on product price
     * 
     * @param string $price_html
     * @param WC_Product $product
     * 
     * @return string $sign_up_fee || $price_html
     */
    public static function display_sign_up_fee_on_price($price_html, $product)
    {
        // Sometimes the hook gets an array type product
        $product_id = is_object($product)? $product->get_id() : $product['product_id'];

        // Avoid non subscription products
        if (!\MobbexSubscription\Product::is_subscription($product_id))
            return $price_html;

        // Set sign up price
        $sign_up_price = self::get_product_subscription_signup_fee($product_id);

        return $sign_up_price ? $price_html .= __(" /month and a $$sign_up_price sign-up fee") : $price_html;
    }

    /*
     * Get product subscription sign-up fee from db
     * 
     * @param int|string $id
     * 
     * @return string|null product subscription sign-up fee
     */
    public static function get_product_subscription_signup_fee($id)
    { 
        try {
            $subscription = \MobbexSubscription\Subscription::get_by_id($id);
            if (!$subscription)
                return null;

            return isset($subscription->signup_fee) !== '0.00' ? $subscription->signup_fee : null;
        } catch (\Exception $e) {
            self::$logger->log('error', '\MobbexSubscription\Helper > get_product_subscription_signup_fee | Failed obtaining setup fee: ' . $e->getMessage(), $subscription);
        }
    }
}