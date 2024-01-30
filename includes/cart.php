<?php

/**
 * Mirrors a few functions in WC_Cart class to work for subscriptions.
 */
class Mbbxs_Cart
{
    /** @var Mbbxs_Helper */
    public static $helper;

    public static function init()
    {
        // Load helper
        self::$helper = new Mbbxs_Helper;

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

        if (\Mbbxs_Helper::cart_has_subscription() || self::$helper->cart_has_wcs_subscription()) {
            wc_add_notice(__("You can't add items in a cart with a subscription, please clean your cart before.", 'mobbex-for-woocommerce'), 'error');
            return false;
        } else if (!empty(WC()->cart->get_cart()) && (Mbbx_Subs_Product::is_subscription($product_id) || strpos($product->get_type(), 'subscription') !== false)) {
            wc_add_notice(__("You can't add a subscription in a cart with items, please clean your cart before.", 'mobbex-for-woocommerce'), 'error');
            return false;
        }

        return $valid;
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
        if (\Mbbxs_Helper::cart_has_subscription() || self::$helper->cart_has_wcs_subscription()) {
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
        if (self::$helper::cart_has_subscription() || self::$helper::order_has_subscription()) {
            // Remove all payment gateways except mobbex
            $available_gateways = array_intersect_key($available_gateways, $mobbex_gateway);
        } else if (self::$helper->cart_has_wcs_subscription()) {
            // Nothing
        } else {
            // By default, remove mobbex from available gateways
            $available_gateways = array_diff_key($available_gateways, $mobbex_gateway);
        }

        return $available_gateways;
    }
}
