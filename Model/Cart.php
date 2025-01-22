<?php
namespace MobbexSubscription;
/**
 * Mirrors a few functions in WC_Cart class to work for subscriptions.
 */
class Cart
{
    /** @var \MobbexSubscription\Helper */
    public static $helper;

    /** @var \MobbexSubscription\OrderHelper */
    public static $order_helper;

    public static function init()
    {
        // Load helpers
        self::$helper = new \MobbexSubscription\Helper;
        self::$order_helper = new \MobbexSubscription\OrderHelper;

        // Validate cart items
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_cart_items'], 10, 2);
        add_filter('woocommerce_update_cart_validation', [self::class, 'validate_cart_update'], 10, 4);

        // Redirect to checkout on subscription sign up 
        add_filter('woocommerce_add_to_cart_redirect',  [self::class, 'redirect_signup_to_checkout']);

        // Change add to cart text on Mobbex Subscriptions
        add_filter('woocommerce_product_add_to_cart_text', [self::class, 'display_signup_button'], 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [self::class, 'display_signup_button'], 10, 2);

        // Change price to show sign up fee on it
        add_filter('woocommerce_get_price_html', [self::$helper, 'display_sign_up_fee_on_price'], 10, 2);
        add_filter('woocommerce_cart_item_price', [self::$helper, 'display_sign_up_fee_on_price'], 10, 2);

        // Maybe add sign-up to totals to mobbex subscription product
        add_filter('woocommerce_cart_calculate_fees', [self::class, 'maybe_add_mobbex_subscription_fee'], 10, 2);
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
        if (\MobbexSubscription\Product::is_subscription($product->get_id()))
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
        } else if (!empty(WC()->cart->get_cart()) && (\MobbexSubscription\Product::is_subscription($product_id) || strpos($product->get_type(), 'subscription') !== false)) {
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
            if (\MobbexSubscription\Product::is_subscription($item['product_id']))
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

    public static function get_subscription($product_id)
    {    
        if (self::has_subscription() || self::has_wcs_subscription())
            return \MobbexSubscription\Subscription::get_by_id($product_id);
        
        wc_add_notice(__("Cart has not subscription or integration is not activated.", 'mobbex-for-woocommerce'), 'error');
        return null;
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
        if (isset($_REQUEST['add-to-cart']) && is_numeric($_REQUEST['add-to-cart']) && \MobbexSubscription\Product::is_subscription((int) $_REQUEST['add-to-cart']))
            $url = wc_get_checkout_url();

        return $url;
    }

    /**
     * Remove items from cart by type.
     * 
     * @param string $type 'any' | 'subs'
     */
    public static function remove_cart_items($type = 'any')
    {
        $cart_items = !empty(WC()->cart->get_cart()) ? WC()->cart->get_cart() : [];

        foreach ($cart_items as $item_key => $item) {
            if ($type == 'any') {
                WC()->cart->set_quantity($item_key , 0);
            } else if ($type == 'subs' && \MobbexSubscription\Product::is_subscription($item['product_id'])) {
                WC()->cart->set_quantity($item_key , 0);
            }
        }
    }

    /**
	 * Check if the current Cart has a Mobbex Subscription product.
	 *
     * @return bool
	 */
    public static function cart_has_subscription()
    {
        $cart_items = WC()->cart ? WC()->cart->get_cart() : [];

        foreach ($cart_items as $item_key => $item) {
            if (\MobbexSubscription\Product::is_subscription($item['product_id']))
                return true;
        }

        return false;
	}

    /**
     * Add mobbex subscription fee to cart
     * 
     * @param WC_Cart $cart
     */
    public static function maybe_add_mobbex_subscription_fee($cart)
    {
        if ($cart->is_empty())
            return;

        foreach ( $cart->get_cart() as $item ){
            $subscription = \MobbexSubscription\Subscription::get_by_id($item['product_id']);
            isset($subscription->singup_fee) ? $cart->add_fee(__("{$subscription->name} Sign-up Fee", 'woocommerce'), $subscription->singup_fee, false) : '';
        }
    }
}
