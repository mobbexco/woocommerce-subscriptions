<?php
namespace Mobbex\WP\Subscriptions\Model;
require_once 'utils.php';
use \Mobbex\WP\Subscriptions\Model\SubscriptionProduct as SubscriptionProduct;

/**
 * Mirrors a few functions in WC_Cart class to work for subscriptions.
 */
class Cart
{
    /** @var Mobbex\WP\Subscriptions\Model\Helper */
    public static $helper;

    /** @var Mobbex\WP\Subscriptions\Helper\Order */
    public static $order_helper;

    /** @var Mobbex\WP\Subscriptions\Helper\Cart */
    public static $cart_helper;

    public static function init()
    {
        // Load helpers
        self::$helper       = new \Mobbex\WP\Subscriptions\Model\Helper;
        self::$order_helper = new \Mobbex\WP\Subscriptions\Helper\Order;
        self::$cart_helper  = new \Mobbex\WP\Subscriptions\Helper\Cart;

        // Validate cart items
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_cart_items'], 10, 2);
        add_filter('woocommerce_update_cart_validation', [self::class, 'validate_cart_update'], 10, 4);

        // Redirect to checkout on subscription sign up 
        add_filter('woocommerce_add_to_cart_redirect',  [self::class, 'redirect_signup_to_checkout']);

        // Change add to cart text on Mobbex Subscriptions
        add_filter('woocommerce_product_add_to_cart_text', [self::class, 'display_signup_button'], 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [self::class, 'display_signup_button'], 10, 2);

        // Change price to show sign up fee on it
        add_filter('woocommerce_get_price_html', [self::class, 'display_sign_up_fee_on_price'], 10, 2);
        add_filter('woocommerce_cart_item_price', [self::class, 'display_sign_up_fee_on_price'], 10, 2);

        // Maybe add sign-up to totals to mobbex subscription product
        add_filter('woocommerce_cart_calculate_fees', [self::class, 'maybe_add_mobbex_subscription_fee'], 10, 2);

        // Disable the rest of payment gateways when there is a mobbex subscription
        add_filter('woocommerce_available_payment_gateways', [self::class, 'filter_checkout_payment_gateways']);
    }

    /**
     * Display signup button changing text of add to cart on mobbex subscriptions.
     * 
     * @param string $text
     * @param WC_Product $product
     * 
     * @return string $text
     */
    public static function display_signup_button($text, $product)
    {
        if (\Mobbex\WP\Subscriptions\Model\SubscriptionProduct::is_subscription($product->get_id()))
            $text = __('Sign Up', 'mobbex-subs-for-woocommerce');

        return $text;
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
        // Sometimes the hook gets an array type product 
        if (!is_object($product))
            return;

        // Avoid non-mobbex subscription products
        if (self::$helper->is_wcs_active() && WC_Subscriptions_Product::is_subscription($product->get_id()))
            return $price_html;

        if (!SubscriptionProduct::is_subscription($product->get_id()))
            return $price_html;

        // Set sign up price
        $sign_up_price = SubscriptionProduct::get_signup_fee($product->get_id());

        return $sign_up_price ? $price_html .= __(" /month and a $$sign_up_price sign-up fee") : $price_html;
    }

    /**
     * Add mobbex subscription fee to cart and checkout if it exists in the product
     * 
     * @param WC_Cart $cart
     */
    public static function maybe_add_mobbex_subscription_fee($cart){
        foreach ( $cart->get_cart() as $item )
            $sign_up_price=  SubscriptionProduct::get_signup_fee($item['product_id']);

        $sign_up_price ? $cart->add_fee( __('Sign-up Fee', 'woocommerce') , $sign_up_price, false ) : '';
    }

    /**
     * Validate Cart items.
     * Avoid have more than one subscription or items with subscriptions in cart.
     * 
     * @param bool $valid
     * @param int $product_id
     * 
     * @return bool $valid
     */
    public static function validate_cart_items($valid, $product_id)
    {
        $product = wc_get_product($product_id);

        if (self::$cart_helper->cart_has_subscription() || self::$cart_helper->cart_has_wcs_subscription()) {
            wc_add_notice(__("You can't add items in a cart with a subscription, please clean your cart before.", 'mobbex-for-woocommerce'), 'error');
            return false;
        } else if (!empty(WC()->cart->get_cart()) && (SubscriptionProduct::is_subscription($product_id) || strpos($product->get_type(), 'subscription') !== false)) {
            wc_add_notice(__("You can't add a subscription in a cart with items, please clean your cart before.", 'mobbex-for-woocommerce'), 'error');
            return false;
        }

        return $valid;
    }

    /**
	 * Check if the current Cart has a Mobbex Subscription product.
	 *
     * @return bool
	 */
    public static function has_subscription()
    {
        $cart_items = WC()->cart ? WC()->cart->get_cart() : [];

        foreach ($cart_items as $item_key => $item) {
            if (SubscriptionProduct::is_subscription($item['product_id']))
                return true;
        }

        return false;
	}

    /**
     * Check if current cart (or pending order) has a wcs subscription.
     * 
     * @return bool|null Null if wcs is inactive.
     */
    public static function has_wcs_subscription()
    {
        if (!self::$helper->is_wcs_active())
            return;

        // Try to get pending order (for manual renewals)
        $pending_order = wc_get_order(get_query_var('order-pay'));

        return \WC_Subscriptions_Cart::cart_contains_subscription()
            || wcs_cart_contains_renewal()
            || ($pending_order && wcs_order_contains_subscription($pending_order));
    }

    /**
     * Validate cart items update. 
     * Avoid to increase the number of subscriptions in a cart.
     * @param bool $valid
     * @param string $cart_item_key
     * @param array $values
     * @param string $quantity
     * 
     * @return bool
     */
    public static function validate_cart_update($valid, $cart_item_key, $values, $quantity)
    {
        if (self::$cart_helper->cart_has_subscription() || self::$cart_helper->cart_has_wcs_subscription()) {
            wc_add_notice(__("You can't have more than one subscription per cart.", 'mobbex-for-woocommerce'), 'error');
            return false;
        }

        return true;
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
        if (isset($_REQUEST['add-to-cart']) && is_numeric($_REQUEST['add-to-cart']) && SubscriptionProduct::is_subscription((int) $_REQUEST['add-to-cart']))
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

        // Get gateway id formatted
        $mobbex_gateway = [MOBBEX_SUBS_WC_GATEWAY_ID => true];

        // If cart has a mobbex subscription
        if (self::has_subscription() || self::$order_helper::order_has_subscription()) {
            // Remove all payment gateways except mobbex
            $available_gateways = array_intersect_key($available_gateways, $mobbex_gateway);
        } else if (self::$cart_helper->cart_has_wcs_subscription()) {
            if(isset($available_gateways['mobbex']))
            unset($available_gateways['mobbex']);
        } else {
            // By default, remove mobbex from available gateways
            $available_gateways = array_diff_key($available_gateways, $mobbex_gateway);
        }

        return $available_gateways;
    }
}
