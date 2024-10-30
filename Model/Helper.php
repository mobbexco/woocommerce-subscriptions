<?php
namespace MobbexSubscription;
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
    public static function display_sign_up_fee_on_price($price_html, $product)
    {
        // Sometimes the hook gets an array type product and avoid non subscription products
        if (!is_object($product) || !\MobbexSubscription\Product::is_subscription($product->get_id()) )
            return $price_html;

        // Set sign up price
        $sign_up_price = self::get_product_subscription_signup_fee($product->get_id());

        return $sign_up_price ? $price_html .= __(" /month and a $$sign_up_price sign-up fee") : $price_html;
    }

    /*
     * Get product subscription sign-up fee from cache or API
     * 
     * @param int|string $id
     * 
     * @return int|string product subscription sign-up fee
     */
    public static function get_product_subscription_signup_fee($id)
    { 
        try {
            // Try to get subscription data from cache; otherwise it get it from API
            $subscription = \MobbexSubscription\Subscription::get_by_id($id);
            return isset($subscription['setupFee']) ? $subscription['setupFee'] : '';
        } catch (\Exception $e) {
            (new \Mobbex\WP\Checkout\Model\Logger)->log('error', '\MobbexSubscription\Helper > get_product_subscription_signup_fee | Failed obtaining setup fee: ' . $e->getMessage(), $subscription);
        }
    }
}