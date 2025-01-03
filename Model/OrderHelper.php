<?php
namespace MobbexSubscription;
/**
 * Mobbex Subscription Order Helper
 */
class OrderHelper
{
    /** @var Mobbex\Api */
    public $api;

    /** @var \MobbexSubscription\Helper */
    public $helper;

    public function __construct() {
        $this->api    = new \Mobbex\Api();
        $this->helper = new \MobbexSubscription\Helper();
    }

     /**
     * Check if Order has a Mobbex Subscription product or a WCS Subscription.
     *
     * @param integer $order_id
     * 
     * @return bool
     */
    public static function has_any_subscription($order_id)
    {
        return \MobbexSubscription\Cart::has_subscription($order_id) || \MobbexSubscription\Helper::is_wcs_active() && (wcs_is_subscription($order_id) || wcs_order_contains_subscription($order_id));
    }

    /**
	 * Check if Order has a Mobbex Subscription product.
	 *
	 * @param integer $order_id
     * 
     * @return bool
	 */
    public static function has_subscription($order_id)
    {
        // Search subscription products in Order
        $order = wc_get_order($order_id);

		foreach ($order->get_items() as $item) {
            $product_id = $item->get_product()->get_id();

            if (\MobbexSubscription\Product::is_subscription($product_id))
                return true;
        }

        return false;
	}

    /**
     * Check if the current Order has a Mobbex Subscription product.
     *
     * @return bool
     */
    public static function order_has_subscription()
    {
        if (empty($_GET['pay_for_order']) || empty(get_query_var('order-pay')))
            return false;

        $order = wc_get_order(get_query_var('order-pay'));

        foreach ($order->get_items() as $item) {
            if (\MobbexSubscription\Product::is_subscription($item->get_product()->get_id()))
                return true;
        }

        return false;
    }

    /**
     * Retry Subscription execution using Mobbex API.
     * 
     * @param string|int $order_id
     * @param string|int $execution_id
     * 
     * @return bool $result
     */
    public function retry_execution($order_id, $execution_id)
    {
        if (!$this->is_ready()) {
            throw new Exception(__('Plugin is not ready', 'mobbex-subs-for-woocommerce'));
        }

        // Query params
        $params = [
            'id'  => get_post_meta($order_id, 'mobbex_subscription_uid'),
            'sid' => get_post_meta($order_id, 'mobbex_subscriber_uid'),
            'eid' => $execution_id,
        ];

        // Request data 
        $data = [
            'method' => 'GET',
            'params' => $params,
            'uri'    => "subscriptions/{$params['id']}/subscriber/{$params['sid']}/execution/{$params['eid']}/action/retry",
        ];

        // Retry excecution
        $response = $this->api::request($data);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result'])) {
                return true;
            }
        }

        throw new Exception(__('An error occurred in the execution', 'mobbex-subs-for-woocommerce'));
    }

    /**
     * Update order total.
     * 
     * @param WC_Order|WC_Subscription $order
     * @param int|string $total
     */
    public function update_order_total($order, $total)
    {
        if ($total == $order->get_total())
            return;

        // Create an item with total difference
        $item = new WC_Order_Item_Fee();

        $item->set_name(__('Modificación de monto', 'mobbex-subs-for-woocommerce'));
        $item->set_amount($total - $order->get_total());
        $item->set_total($total - $order->get_total());

        // Add the item and recalculate totals
        $order->add_item($item);
        $order->calculate_totals();
    }

    /**
     * Get the post id for 2.x subs compatibility.
     * 
     * @param string $product_id
     * @param mixed $order
     * 
     * @return string
     */
    public function get_post_id($product_id, $order)
    {
        if ($order && \MobbexSubscription\Helper::is_wcs_active()) {
            $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
            $wcs_sub = end($subscriptions);
            return  \MobbexSubscription\Subscription::get_by_id($wcs_sub->order->get_id(), false) ? $wcs_sub->order->get_id() : $product_id;
        } else {
            return $product_id;
        }
    }
}