<?php
/**
 * For use in Standalone mode.
 */
namespace MobbexSubscription;

class Product
{
    /**
     * Mobbex meta fields saved.
     */
    protected static $mbbx_meta_fields = [
        'mbbxs_subscription_mode',
        'mbbxs_charge_interval',
        'mbbxs_free_trial',
        'mbbxs_signup_fee',
        'mbbxs_test_mode'
    ];

    /**
     *  Retrieve true if Product is a Mobbex Subscription.
     * 
     * @param integer $product_id
     * @return boolean
     */
    public static function is_subscription($product_id = null)
    {
        // If not sent get it directly from the current post
        if ($product_id === null) {
            $product_id = get_the_ID();
        }

        return (bool) get_post_meta($product_id, 'mbbxs_subscription_mode', true);
    }

    /**
     *  Retrieve Charge interval from a subscription product.
     * 
     * @param integer $product_id
     * @return array|null
     */
    public static function get_charge_interval($product_id = null)
    {
        // If not sent get it directly from the current post
        if ($product_id === null) {
            $product_id = get_the_ID();
        }

        $data = get_post_meta($product_id, 'mbbxs_charge_interval', true);
        $default = [
            'interval' => null, 
            'period' => null
        ];

        return !empty($data) ? $data : $default;  
    }

    /**
     *  Retrieve Free trial configs from a subscription product.
     * 
     * @param integer $product_id
     * @return array|null
     */
    public static function get_free_trial($product_id = null)
    {
        // If not sent get it directly from the current post
        if ($product_id === null) {
            $product_id = get_the_ID();
        }

        $data = get_post_meta($product_id, 'mbbxs_free_trial', true);
        $default = [
            'interval' => null, 
            'period' => null
        ];

        return !empty($data) ? $data : $default;  
    }

    /**
     *  Retrieve Sign-up fee.
     * 
     * @param integer $product_id
     * @return integer
     */
    public static function get_signup_fee($product_id = null)
    {
        // If not sent get it directly from the current post
        if ($product_id === null) {
            $product_id = get_the_ID();
        }

        return (int) get_post_meta($product_id, 'mbbxs_signup_fee', true);
    }

    /**
     *  Retrieve test mode.
     * 
     * @param integer $product_id
     * @return integer
     */
    public static function get_test_mode($product_id = null)
    {
        // If not sent get it directly from the current post
        if ($product_id === null)
            $product_id = get_the_ID();

        return get_post_meta($product_id, 'mbbxs_test_mode', true);
    }
}