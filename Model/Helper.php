<?php
namespace Mobbex\WP\Subscriptions\Model;
require_once 'utils.php';

class Helper
{
    /** @var Mobbex\Api */
    public $api;

    public function __construct()
    {
        // Init settings (Full List in WC_Gateway_Mobbex_Subs::init_form_fields)
        $option_key = 'woocommerce_' . MOBBEX_SUBS_WC_GATEWAY_ID . '_settings';
        $settings   = get_option($option_key, null) ?: [];
        foreach ($settings as $key => $value) {
            $key = str_replace('-', '_', $key);
            $this->$key = $value;
        }
        // Instance SDK classes
        $this->api = new \Mobbex\Api();

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

    public function generate_token()
    {
        return md5($this->api_key . '|' . $this->access_token);
    }

    public function valid_mobbex_token($token)
    {
        return $token == $this->generate_token();
    }

    public function get_api_endpoint($endpoint)
    {
        $query = [
            'mobbex_token' => \Mobbex\Repository::generateToken(),
            'platform' => "woocommerce",
            "version" => MOBBEX_SUBS_VERSION,
        ];

        $query['wc-api'] = $endpoint;

        return add_query_arg($query, home_url('/'));
    }

    public function is_ready()
    {
        return (!empty($this->enabled) && !empty($this->api_key) && !empty($this->access_token) && $this->enabled === 'yes');
    }

    public function is_wcs_active()
    {
        return (!empty($this->integration) && $this->integration === 'wcs' && get_option('woocommerce_subscriptions_is_active'));
    }

    /**
	 * Checks if page is pay for order and change subs payment page.
	 */
    public static function is_subs_change_method()
    {
		return (isset($_GET['pay_for_order']) && isset($_GET['change_payment_method']));
	}

    /**
	 * Get payment state from Mobbex status code.
     * 
     * @param int|string $status
     * 
     * @return string "approved" | "on-hold" | "cancelled"
	 */
    public static function get_state($status)
    {
        if ($status == 2 || $status == 3 || $status == 100 || $status == 201) {
            return 'on-hold';
        } else if ($status == 4 || $status >= 200 && $status < 400) {
            return 'approved';
        } else {
            return 'cancelled';
        }
	}
    
    /**
     * Add subscription product support to checkout gateway
     * 
     * @param array $supports checkout product support
     * 
     * @return array $supports with subscriptions product support added
     */
    public static function add_subscription_support($supports = [])
    {
        $subs_support = [
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change_customer'
        ];
        return array_push($supports, $subs_support);
    }
} 