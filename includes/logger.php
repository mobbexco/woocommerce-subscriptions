<?php
require_once 'utils.php';

class Mbbxs_Logger
{
    public function __construct()
    {
        $this->error  = false;
        $this->helper = new Mbbxs_Helper;

        if (!$this->helper->api_key || !$this->helper->access_token)
            $this->error = self::notice('error', __('You need to specify an API Key and an Access Token.', 'mobbex-for-woocommerce'));

    }

    public function debug($message = 'debug', $data = [], $force = false)
    {
        if ($this->helper->debug_mode != 'yes' && !$force)
            return;

        apply_filters(
            'simple_history_log',
            'Mobbex Subscriptions: ' . $message,
            $data,
            'debug'
        );
    }

    public static function notice($type, $msg)
    {
        add_action('admin_notices', function () use ($type, $msg) {
            $class = esc_attr("notice notice-$type");
            $msg = esc_html($msg);

            ob_start();

?>

            <div class="<?= $class ?>">
                <h2>Mobbex Subscriptions for Woocommerce</h2>
                <p><?= $msg ?></p>
            </div>

<?php

            echo ob_get_clean();
        });
    }
}