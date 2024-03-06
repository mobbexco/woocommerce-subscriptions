<?php

/**
 * Mobbex Subscription Order Helper
 */
class Mbbxs_Subs_Order
{
    /** @var Mobbex\Api */
    public $api;

    /** @var Mbbxs_Helper */
    public $helper;

    public function __construct() {
        $this->api = new \Mobbex\Api();
        $this->helper = new Mbbxs_Helper();
    }

     /**
     * Check if Order has a Mobbex Subscription product or a WCS Subscription.
     *
     * @param integer $order_id
     * 
     * @return bool
     */
    public function has_any_subscription($order_id)
    {
        return \Mbbxs_Cart::has_subscription($order_id) || $this->helper->is_wcs_active() && (wcs_is_subscription($order_id) || wcs_order_contains_subscription($order_id));
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
            if (Mbbx_Subs_Product::is_subscription($item->get_product()->get_id()))
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
        if ($order && $this->helper->is_wcs_active()) {
            $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
            $wcs_sub = end($subscriptions);
            return \MobbexSubscription::is_stored($wcs_sub->order->get_id()) ? $wcs_sub->order->get_id() : $product_id;
        } else {
            return $product_id;
        }
    }

    /**
     * Store old 2.x subscribers & subscriptions in database
     * 
     * @param WC_Order|WC_Abstract_Order $order
     */
    public function maybe_migrate_subscriptions($order)
    {
        foreach ($order->get_items() as $item) {

            $old_subscription = get_post_meta($order->get_id(), 'mobbex_subscription', true);
            $old_subscriber   = get_post_meta($order->get_id(), 'mobbex_subscriber', true);

            //Migrate data if there are an old subscription
            if($old_subscription){
                //get type
                $type = $this->helper->is_wcs_active() ? 'manual' : 'dynamic';

                //Load subscription
                $subscription = new \MobbexSubscription(
                    $order->get_id(),
                    "wc_order_{$order->get_id()}", 
                    isset($old_subscription['total']) ? $old_subscription['total'] : '',
                    isset($old_subscription['setupFee']) ? $old_subscription['setupFee'] : '',
                    $type,
                    isset($old_subscription['name']) ? $old_subscription['name'] : '',
                    isset($old_subscription['description']) ? $old_subscription['description'] : '',
                    isset($old_subscription['interval']) ? $old_subscription['interval'] : '',
                    isset($old_subscription['trial']) ? $old_subscription['trial'] : '',
                    isset($old_subscription['limit']) ? $old_subscription['limit'] : '',
                );

                //Set uid
                $subscription->uid        = isset($old_subscription['uid']) ? $old_subscription['uid'] : '';
                $subscription->return_url = isset($old_subscription['return_url']) ? $old_subscription['return_url'] : '';
                $subscription->webhook    = isset($old_subscription['webhook']) ? $old_subscription['webhook'] : '';

                //Save the data
                $subscription->save();

                //update metapost
                update_post_meta($order->get_id(), 'mobbex_subscription', '');
            }

            //Migrate data if there are an old subscriber
            if($old_subscriber){
                //load Subscriber
                $subscriber = new \MobbexSubscriber(
                    $order->get_id(),
                    isset($old_subscription['uid']) ? $old_subscription['uid'] : '',
                    isset($old_subscriber['reference']) ? $old_subscriber['reference'] : '',
                    $order->get_billing_first_name(),
                    $order->get_billing_email(),
                    $order->get_billing_phone(),
                    get_post_meta($order->get_id(), !empty($this->helper->custom_dni) ? $this->helper->custom_dni : '_billing_dni', true),
                    $order->get_customer_id(),
                );

                //set other data
                $subscriber->uid         = isset($old_subscriber['uid']) ? $old_subscriber['uid'] : '';
                $subscriber->source_url  = isset($old_subscriber['sourceUrl']) ? $old_subscriber['sourceUrl'] : '';
                $subscriber->control_url = isset($old_subscriber['subscriberUrl']) ? $old_subscriber['subscriberUrl'] : '';

                //Save the data
                $subscriber->save(false);

                //update metapost
                update_post_meta($order->get_id(), 'mobbex_subscriber', '');
            }
        }
    }
}