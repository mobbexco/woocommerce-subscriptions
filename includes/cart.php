<?php
require_once 'utils.php';

/**
 * Mirrors a few functions in WC_Cart class to work for subscriptions.
 */
class Mbbxs_Cart
{
    public static Mbbx_Subs_Helper $helper;

    public static function init()
    {
        // Load helper
        self::$helper = new Mbbx_Subs_Helper;

        // Validate cart items
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_cart_items'], 10, 2);

        // Redirect to checkout on subscription sign up 
        add_filter('woocommerce_add_to_cart_redirect',  [self::class, 'redirect_signup_to_checkout']);

        // Change add to cart text on Mobbex Subscriptions
        add_filter('woocommerce_product_add_to_cart_text', [self::class, 'display_signup_button']);
        add_filter('woocommerce_product_single_add_to_cart_text', [self::class, 'display_signup_button']);

        // Disable the rest of payment gateways when there is a mobbex subscription
        add_filter('woocommerce_available_payment_gateways', [self::class, 'filter_checkout_payment_gateways']);
    }

    /**
     * Display signup button changing text of add to cart on mobbex subscriptions.
     * 
     * @param string $text
     * 
     * @return string $text
     */
    public static function display_signup_button($text)
    {
        global $product;

        if (Mbbx_Subs_Product::is_subscription($product->get_id()))
            $text = __('Sign Up', 'mobbex-subs-for-woocommerce');

        return $text;
    }

    /**
     * Validate Cart items.
     * 
     * The checkout only works with one mobbex subscription at a time,
     * and does not allow products of other types.
     * 
     * @param bool $valid
     * @param int $product_id
     * 
     * @return bool $valid
     */
    public static function validate_cart_items($valid, $product_id)
    {
        // Alawys remove mobbex subscriptions from cart
        self::$helper::remove_cart_items('subs');

        // If is a subscription remove all other products of cart
        if (Mbbx_Subs_Product::is_subscription($product_id))
            self::$helper::remove_cart_items();

        return $valid;
    }

    /**
     * Redirect subscriptions to checkout when click sign up button.
     * 
     * @param string $url
     * 
     * @return string $url
     */
    public static function redirect_signup_to_checkout($url)
    {
        // If product is of the subscription type
        if (isset($_REQUEST['add-to-cart']) && is_numeric($_REQUEST['add-to-cart']) && Mbbx_Subs_Product::is_subscription((int) $_REQUEST['add-to-cart']))
            $url = wc_get_checkout_url();

        return $url;
	}

    /**
     * Filter checkout payment gateways by product type.
     * 
     * @param array $available_gateways
     * 
     * @return array $available_gateways
     */
    public static function filter_checkout_payment_gateways($available_gateways)
    {
        if (is_admin() && !defined('DOING_AJAX'))
            return;

        // Filter available gateways by key
        if (self::$helper::cart_has_subscription()) {
            $whitelist = [MOBBEX_SUBS_WC_GATEWAY_ID => true];
            $available_gateways = array_intersect_key($available_gateways, $whitelist);
        }

        return $available_gateways;
    }
}