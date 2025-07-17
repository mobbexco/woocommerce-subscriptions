<?php

class Mbbx_Subs_Order_Settings
{
    /** @var Mbbxs_Helper */
    public static $helper;

    public static function init()
    {
        // Load helper
        self::$helper = new Mbbxs_Helper;

        // Add subscription panel to Order admin page
        add_action('add_meta_boxes', [self::class, 'add_subscription_panel']);

        // Add some scripts
        add_action('admin_enqueue_scripts', [self::class, 'load_scripts']);

        // Retry execution endpoint for subscriptions panel
        add_action('woocommerce_api_mbbxs_retry_execution', [self::class, 'retry_execution_endpoint']);

        // Add action to modify subscription total
        add_action('woocommerce_order_actions', [self::class, 'add_subscription_actions']);
        add_action('wcs_view_subscription_actions', [self::class, 'add_subscription_actions']);

        // Modify total endpoint for order action
        add_action('woocommerce_order_action_mbbxs_modify_total', [self::class, 'modify_total_endpoint']);
    }

    /**
     * Display subscription panel in Order admin page.
     */
    public static function add_subscription_panel($screen)
    {
        // Only show in admin order pages
        if (!is_admin() || !in_array($screen, ['shop_order', wc_get_page_screen_id('shop-order')]))
            return;

        $order = wc_get_order();

        // Only show in standalone subscription orders
        if (!$order || !self::$helper->has_subscription($order))
            return;

        add_meta_box('mbbxs_order_panel', __('Subscription Payments','mobbex-subs-for-woocommerce'), [self::class, 'show_subscription_executions'], $screen, 'side', 'core');
    }

    /**
     * Add subscription actions to order actions select.
     *
     * @param array $actions
     * @return array $actions
     */
    public static function add_subscription_actions($actions)
    {
        $order = wc_get_order();

        // Only add actions if order has a subscription
        if (is_admin() && $order && self::$helper->has_any_subscription($order->get_id()))
            $actions['mbbxs_modify_total'] = __('Modificar monto de la suscripción', 'mobbex-subs-for-woocommerce');

        return $actions;
    }

    /**
     * Show subscription executions history in order subscription panel.
     */
    public static function show_subscription_executions()
    {
        $order = wc_get_order();

        // Get payments from webhooks post meta
        $webhooks = $order->get_meta('mbbxs_webhooks', true);
        $payments = !empty($webhooks['payments']) ? $webhooks['payments'] : [];

        // Display subscription payments executions history
        foreach ($payments as $reference => $executions) {
            echo '<table class="mbbxs_payment"><tbody>';

            // Show payment reference
            echo 
            "<tr>
                <td class='mbbx_payment_ref' colspan='3'>
                    <b>" . __('Payment Reference', 'mobbex-subs-for-woocommerce') . "</b>
                    <code>$reference</code>
                </td>
            </tr>";

            foreach ($executions as $key => $execution) {
                $retry = $msg = '';
                $id    = $execution['data']['execution']['uid'];
                $state = self::$helper::get_state($execution['data']['payment']['status']['code']);
                $date  = explode('T', $execution['data']['payment']['created'])[0] ?: __('Missing date', 'mobbex-subs-for-woocommerce');

                // Create messages
                switch ($state) {
                    case 'approved':  $msg = __('Charge Executed', 'mobbex-subs-for-woocommerce'); break;
                    case 'on-hold':   $msg = __('Charge On Hold', 'mobbex-subs-for-woocommerce');  break;
                    case 'failed':    $msg = __('Charge Failed', 'mobbex-subs-for-woocommerce');   break;
                }

                // If it is the last execution for this payment
                if (end(array_keys($executions)) == $key) {
                    // If there were previous payments and this was approved
                    if (count($executions) > 1 && $state == 'approved') {
                        // Render retried message
                        $retry = __('Retried', 'mobbex-subs-for-woocommerce');
                    } elseif ($state == 'failed') {
                        // Render retry button
                        $retry = "<button class='mbbx_retry_btn button' id='$id'>" . __('Retry', 'mobbex-subs-for-woocommerce') . "</button>";
                    }
                }

                // Render execution item
                echo 
                "<tr>
                    <td><b>$date</b></td>
                    <td class='mbbx_msg mbbx_msg_$state'>$msg</td>
                    <td style='text-align: end;'>$retry</td>
                </tr>";
            }

            echo '</tbody></table>';
        }
    }

    /**
     * Load styles and scripts for dynamic options.
     */
    public static function load_scripts($screen)
    {
        $valid_pages = [
            'post.php',
            'post-new.php',
            'shop_order',
            'shop_subscription',
            wc_get_page_screen_id('shop-order'),
            wc_get_page_screen_id('shop-subscription')
        ];

        // Only load on admin order pages
        if (!is_admin() ||!in_array($screen, $valid_pages))
            return;

        $order = wc_get_order();

        // Not show in order list page or other post types
        if (!$order)
            return;

        wp_enqueue_style('mbbxs-order-style', plugin_dir_url(__FILE__) . '../../assets/css/order-admin.css');
        wp_enqueue_script('mbbxs-order', plugin_dir_url(__FILE__) . '../../assets/js/order-admin.js');

        // Add retry endpoint URL to script
        wp_localize_script('mbbxs-order', 'mobbex_data', [
            'order_id'    => $order->get_id(),
            'order_total' => $order->get_total(),
            'retry_url'   => home_url('/wc-api/mbbxs_retry_execution')
        ]);
        wp_enqueue_script('mbbxs-order');
    }

    /**
     * Retry subscription execution payment.
     * 
     * Endpoint called in order subscription panel.
     */
    public static function retry_execution_endpoint()
    {
        $result = $msg = false;

        try {
            // Get request params
            $order_id     = $_REQUEST['order_id'];
            $execution_id = $_REQUEST['execution_id'];

            // Retry execution
            $result = self::$helper->retry_execution($order_id, $execution_id);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }

        wp_send_json([
            'result' => $result,
            'msg'    => $msg,
        ]);
    }

    /**
     * Endpoint to modify subscription total.
     * 
     * Called using order action.
     * 
     * @param WC_Order $order
     */
    public static function modify_total_endpoint($order)
    {
        try {
            // Get "new total" value from post data
            $post_data = wp_unslash($_POST);
            $new_total = !empty($post_data['mbbxs_new_total']) ? $post_data['mbbxs_new_total'] : false;

            // If data look fine
            if (is_numeric($new_total)) {
                $order_id         = $order->get_id();
                $subscription     = $order->get_meta('mobbex_subscription', true);
                $subscription_uid = !empty($subscription['uid']) ? $subscription['uid'] : $order->get_meta('mobbex_subscription_uid', true);

                $result = self::$helper->modify_subscription($subscription_uid, ['total' => $new_total]);

                if ($result) {
                    self::$helper->update_order_total($order, $new_total);
                    $order->add_order_note(__('Monto de suscripción modificado: $' , 'mobbex-subs-for-woocommerce') . $new_total);
                }
            }
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $order->add_order_note(__('Error al modificar el monto: ', 'mobbex-subs-for-woocommerce') . $msg);
        }
    }
}