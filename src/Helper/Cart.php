<?php

namespace Mobbex\WP\Subscriptions\Helper;
use \Mobbex\WP\Subscriptions\Model\SubscriptionProduct as SubscriptionProduct;

class Cart
{
    /** @var Mobbex\Api */
    public $api;

    /** @var Helper */
    public $helper;

    /**@var Mobbex\WP\Subscriptions\Model\SubscriptionProduct */
    public $subscription_product;

    public function __construct()
    {
        $this->api    = new \Mobbex\Api();
        $this->helper = new \Mobbex\WP\Subscriptions\Model\Helper();
    }

    /**
	 * Check if the current Cart has a Mobbex Subscription product.
	 *
     * @return bool
	 */
    public function cart_has_subscription()
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
    public function cart_has_wcs_subscription()
    {
        if (!$this->is_wcs_active())
            return;

        // Try to get pending order (for manual renewals)
        $pending_order = wc_get_order(get_query_var('order-pay'));

        return \WC_Subscriptions_Cart::cart_contains_subscription()
            || wcs_cart_contains_renewal()
            || ($pending_order && wcs_order_contains_subscription($pending_order));
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
            if ($type == 'any')
                WC()->cart->set_quantity($item_key , 0);
            else if ($type == 'subs' && SubscriptionProduct::is_subscription($item['product_id']))
                WC()->cart->set_quantity($item_key , 0);
        }
    }
}