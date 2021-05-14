<?php

class Mbbx_Subs_Order_Settings
{
    public static Mbbx_Subs_Helper $helper;

    public static function init()
    {
        // Load helper
        self::$helper = new Mbbx_Subs_Helper;

        // Add subscription panel to Order admin page
        add_action('add_meta_boxes', [self::class, 'add_subscription_panel']);

        // Add some scripts
        add_action('admin_enqueue_scripts', [self::class, 'load_scripts']);

        // Retry execution endpoint for subscriptions panel
        add_action('woocommerce_api_mbbxs_retry_execution', [self::class, 'retry_execution_endpoint']);
    }

    /**
     * Display subscription panel in Order admin page.
     */
    public static function add_subscription_panel()
    {
        add_meta_box('mbbxs_order_panel', __('Subscriptions payments','mobbex-subs-for-woocommerce'), [self::class, 'show_subscription_executions'], 'shop_order', 'side', 'core');
    }

    /**
     * Show subscription executions history in order subscription panel.
     */
    public static function show_subscription_executions()
    {
        global $post;

        // Get data from webhooks post meta
        $webhooks = get_post_meta($post->ID, 'mbbxs_webhooks', true) ?: [];

        echo '<table class="mbbxs_history"><tbody>';

        // Display subscription executions history
        foreach ($webhooks as $reference => $executions) {
            // Separate executions by Reference
            echo "<td colspan='3'>Reference $reference</td>";

            foreach ($executions as $key => $execution) {
                // Only if is a subscription:execution
                if ($execution['type'] != 'subscription:execution') {
                    continue;
                }

                $retry = $msg = '';
                $id    = $execution['data']['execution']['uid'];
                $state = self::$helper::get_state($execution['data']['payment']['status']['code']);
                $date  = explode('T', $execution['data']['payment']['created'])[0] ?: __('Missing date', 'mobbex-subs-for-woocommerce');

                // Create messages
                switch ($state) {
                    case 'approved':  $msg = __('Charge Executed', 'mobbex-subs-for-woocommerce'); break;
                    case 'on-hold':   $msg = __('Charge On Hold', 'mobbex-subs-for-woocommerce');  break;
                    case 'cancelled': $msg = __('Charge Failed', 'mobbex-subs-for-woocommerce');   break;
                }

                // If it is the last execution for this reference
                if (end(array_keys($executions)) == $key) {
                    // If there were previous payments and this was approved
                    if (count($executions) > 1 && $state == 'approved') {
                        // Render retried message
                        $retry = __('Retried', 'mobbex-subs-for-woocommerce');
                    } elseif ($state == 'cancelled') {
                        // Render retry button
                        $retry = "<button class='mbbx_retry_btn button' id='$id'>" . __('Retry', 'mobbex-subs-for-woocommerce') . "</button>";
                    }
                }

                // Render item
                echo 
                "<tr>
                    <td><b>$date</b></td>
                    <td class='mbbx_msg mbbx_msg_$state'>$msg</td>
                    <td style='text-align: end;'>$retry</td>
                </tr>";
            }
        }

        echo '</tbody></table>';
    }

    /**
     * Load styles and scripts for dynamic options.
     */
    public static function load_scripts($hook)
    {
        global $post;

        if (($hook == 'post-new.php' || $hook == 'post.php') && $post->post_type == 'shop_order') {
            wp_enqueue_style('mbbxs-order-style', plugin_dir_url(__FILE__) . '../../assets/css/order-admin.css');
            wp_enqueue_script('mbbxs-order', plugin_dir_url(__FILE__) . '../../assets/js/order-admin.js');

            // Add retry endpoint URL to script
            $mobbex_data = [
                'order_id'  => $post->ID,
                'retry_url' => home_url('/wc-api/mbbxs_retry_execution')
            ];
            wp_localize_script('mbbxs-order', 'mobbex_data', $mobbex_data);
            wp_enqueue_script('mbbxs-order');
        }
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
}