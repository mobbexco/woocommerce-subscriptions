<?php

defined('ABSPATH') || exit;

return [

    /** Subscription options */
    'subscription_configuration_tab' => [
        'title' => __('Mobbex Subscription Configuration', 'mobbex-subs-for-woocommerce'),
        'type'  => 'title',
        'class' => 'mbbx-tab mbbx-tab-subscription',
    ],
    
    'integration' => [
        'title'       => __('Integrate with', 'mobbex-subs-for-woocommerce'),
        'class'       => 'mbbx-into-subscription',
        'type'        => 'select',
        'description' => __('Integrate this plugin with other subscriptions plugins. Detected integrations are displayed', 'mobbex-subs-for-woocommerce'),
        'desc_tip'    => true,
        'options'     => [
            '' => __('None', 'mobbex-subs-for-woocommerce'),
            ]
        ],

    'send_subscriber_email' => [
        'title'   => __('Enable emails to Subscriber', 'mobbex-subs-for-woocommerce'),
        'class'   => 'mbbx-into-subscription',
        'type'    => 'checkbox',
        'label'   => __('Enable Subscriber Emails', 'mobbex-subs-for-woocommerce'),
        'default' => 'yes'
    ]
];