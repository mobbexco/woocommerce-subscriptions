<?php

/**
 * Mirrors a few functions in WC_Cart class to work for subscriptions.
 */
class Mbbxs_Cart
{
    /** @var Mbbxs_Helper */
    public static $helper;

    /** @var Mbbxs_Subs_Order */
    public static $order_helper;

    public static function init()
    {
        // Load helpers
        self::$helper = new Mbbxs_Helper;
        self::$order_helper = new Mbbxs_Subs_Order;

        // Validate cart items
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_cart_items'], 10, 2);
        add_filter('woocommerce_update_cart_validation', [self::class, 'validate_cart_update'], 10, 4);

        // Redirect to checkout on subscription sign up 
        add_filter('woocommerce_add_to_cart_redirect',  [self::class, 'redirect_signup_to_checkout']);

        // Change add to cart text on Mobbex Subscriptions
        add_filter('woocommerce_product_add_to_cart_text', [self::class, 'display_signup_button'], 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [self::class, 'display_signup_button'], 10, 2);

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
        if (Mbbx_Subs_Product::is_subscription($product->get_id()))
            $text = __('Sign Up', 'mobbex-subs-for-woocommerce');

        return $text;
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

        if (self::has_subscription() || self::has_wcs_subscription()) {
            wc_add_notice(__("You can't add items in a cart with a subscription, please clean your cart before.", 'mobbex-for-woocommerce'), 'error');
            return false;
        } else if (!empty(WC()->cart->get_cart()) && (Mbbx_Subs_Product::is_subscription($product_id) || strpos($product->get_type(), 'subscription') !== false)) {
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
            if (Mbbx_Subs_Product::is_subscription($item['product_id']))
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
        if (self::has_subscription() || self::has_wcs_subscription()) {
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

        // Get gateway id formatted
        $mobbex_gateway = [MOBBEX_SUBS_WC_GATEWAY_ID => true];

        // If cart has a mobbex subscription
        if (self::has_subscription() || self::$order_helper::order_has_subscription()) {
            // Remove all payment gateways except mobbex
            $available_gateways = array_intersect_key($available_gateways, $mobbex_gateway);
        } else if (self::has_wcs_subscription()) {
            // Nothing
        } else {
            // By default, remove mobbex from available gateways
            $available_gateways = array_diff_key($available_gateways, $mobbex_gateway);
        }

        return $available_gateways;
    }
}
