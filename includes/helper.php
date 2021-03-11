<?php
require_once 'utils.php';

class MobbexSubsHelper
{

    public function __construct()
    {
        // Init settings (Full List in WC_Gateway_Mobbex_Subs::init_form_fields)
        $option_key = 'woocommerce_' . MOBBEX_SUBS_WC_GATEWAY_ID . '_settings';
        $settings = get_option($option_key, null);
        foreach ($settings as $key => $value) {
            $key = str_replace('-', '_', $key);
            $this->$key = $value;
        }
    }

    public static function notice($type, $msg)
    {
        add_action('admin_notices', function () use ($type, $msg) {
            $class = esc_attr("notice notice-$type");
            $msg = esc_html($msg);

            ob_start();

            ?>

            <div class="<?=$class?>">
                <h2>Mobbex for Woocommerce Subscriptions</h2>
                <p><?=$msg?></p>
            </div>

            <?php

            echo ob_get_clean();
        });
    }

    public static function _redirect_to_cart_with_error($error_msg)
    {
        wc_add_notice($error_msg, 'error');
        wp_redirect(wc_get_cart_url());

        return array('result' => 'error', 'redirect' => wc_get_cart_url());
    }

    public static function display_mobbex_button($checkout)
    {
        ?>
        <!-- Mobbex Button -->
        <div id="mbbx-button"></div>
        <?php
    }
    
    public function generate_token()
    {
        return md5($this->api_key . '|' . $this->access_token);
    }

    public function valid_mobbex_token($token)
    {
        return $token == $this->generate_token();
    }

    public function get_api_endpoint($endpoint, $order_id)
    {
        $query = [
            'mobbex_token' => $this->generate_token(),
            'platform' => "woocommerce",
            "version" => MOBBEX_SUBS_VERSION,
        ];

        if ($order_id) {
            $query['mobbex_order_id'] = $order_id;
        }

        $query['wc-api'] = $endpoint;

        return add_query_arg($query, home_url('/'));
    }
}
