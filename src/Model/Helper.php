<?php
namespace Mobbex\WP\Subscriptions\Model;

class Helper
{
    /** @var Mobbex\Api */
    public $api;

    /** @var Mobbex\WP\Checkout\Model\Config */
    public $config;

    public function __construct()
    {
        // Init settings (Full List in WC_Gateway_Mobbex_Subs::init_form_fields)
        $option_key = 'woocommerce_' . MOBBEX_SUBS_WC_GATEWAY_ID . '_settings';
        $settings   = get_option($option_key, null) ?: [];
        foreach ($settings as $key => $value) {
            $key = str_replace('-', '_', $key);
            $this->$key = $value;
        }
        // Instance SDK and Checkout classes
        $this->api    = new \Mobbex\Api();
        $this->config = new \Mobbex\WP\Checkout\Model\Config;

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
            'platform'     => "woocommerce",
            "version"      => MOBBEX_SUBS_VERSION,
            'mobbex_token' => \Mobbex\Repository::generateToken(),
        ];

        $query['wc-api'] = $endpoint;

        return add_query_arg($query, home_url('/'));
    }

    public function is_ready()
    {
        return (!empty($this->config->enabled) && !empty($this->config->api_key) && !empty($this->config->access_token) && $this->config->enabled === 'yes');
    }

    public function is_wcs_active()
    {
        return (!empty($this->config->integration) && $this->config->integration === 'wcs' && get_option('woocommerce_subscriptions_is_active'));
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
        return array_merge($supports, $subs_support);
    }

    /**
     * Add subscriptions settings options to checkout
     * 
     * @param array $options checkout settings options
     * 
     * @return array
     */
    public static function add_subscription_options($options)
    {
        return array_merge($options, include(plugin_dir_path(__DIR__) . 'admin/config-options.php'));
    }
} 