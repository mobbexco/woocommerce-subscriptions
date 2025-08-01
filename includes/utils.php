<?php

// Defines
define('MOBBEX_SUBSCRIPTION', 'https://api.mobbex.com/p/subscriptions/{id}/subscriber/{sid}/execution');
define('MOBBEX_CREATE_SUBSCRIPTION', 'https://api.mobbex.com/p/subscriptions');
define('MOBBEX_MODIFY_SUBSCRIPTION', 'https://api.mobbex.com/p/subscriptions/{id}');
define('MOBBEX_CREATE_SUBSCRIBER', 'https://api.mobbex.com/p/subscriptions/{id}/subscriber');
define('MOBBEX_RETRY_EXECUTION', 'https://api.mobbex.com/p/subscriptions/{id}/subscriber/{sid}/execution/{eid}/action/retry');

// Coupon URL
define('MOBBEX_SUBS_COUPON', 'https://mobbex.com/console/{entity.uid}/operations/?oid={payment.id}');

define('MOBBEX_SUBS_WC_GATEWAY', 'WC_Gateway_Mbbx_Subs');
define('MOBBEX_SUBS_WC_GATEWAY_ID', 'mobbex_subs');

define('MOBBEX_SUBS_VERSION', '3.3.5');

function mbbxs_log($level, $msg, $data = []) {
    apply_filters(
        'simple_history_log',
        '[Mobbex Subscriptions] ' . $msg,
        $data,
        $level
    );
}

function mbbx_http_error($code, $message, ...$data) {
    mbbxs_log('error', $message, $data);

    http_response_code($code);
    echo $message, exit;
}