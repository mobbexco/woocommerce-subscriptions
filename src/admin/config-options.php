<?php

defined('ABSPATH') || exit;

return [

    /** Subscription options */
    'subscription_tab' => [
        'title' => __('Mobbex Subscription Configuration', 'mobbex-for-woocommerce'),
        'type'  => 'title',
        'class' => 'mbbx-tab mbbx-tab-subscription',
    ],
    
    'integration' => [
        'title'       => __('Integrate with', 'mobbex-for-woocommerce'),
        'class'       => 'mbbx-into-subscription',
        'type'        => 'select',
        'description' => __('Integrate this plugin with other subscriptions plugins. Detected integrations are displayed', 'mobbex-for-woocommerce'),
        'desc_tip'    => true,
        'options'     => [
            ''      => __('None', 'mobbex-for-woocommerce'),
            'wcs'   => __('Woocommerce Subscriptions', 'mobbex-for-woocommerce')
            ]
        ],

    'send_subscriber_email' => [
        'title'   => __('Enable emails to Subscriber', 'mobbex-for-woocommerce'),
        'class'   => 'mbbx-into-subscription',
        'type'    => 'checkbox',
        'label'   => __('Enable Subscriber Emails', 'mobbex-for-woocommerce'),
        'default' => 'yes'
    ]
];