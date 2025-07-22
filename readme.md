# Mobbex Subscriptions for WooCommerce
Plugin that integrates Mobbex Subscriptions functionality to WooCommerce and WooCommerce Subscriptions.
## Requirements
- PHP 7.4 -> 8.4
- Wordpress 5.0 -> 6.8.2
- WooCommerce 3.5.2 -> 9.7.1
- WooCommerce Subscriptions 2.0 -> 7.7.0
## Installation
1) Get the latest version of the plugin
2) Get into Plugins -> Add New
3) Hit the Upload plugin button
4) Select the zip file and upload
5) Activate the plugin
## Configuration
1) Configure api-key and access token in plugin settings.

    > To use in conjunction with [WooCommerce Subscriptions](https://woocommerce.com/es-es/products/woocommerce-subscriptions/) plugin, you must select the WooCommerce Subscriptions option in the plugin integrations field. This option will only be displayed if you have the WooCommerce Subscriptions plugin configured correctly.
2) Create a new product with "Subscription Mode" option enabled.
3) Manage your subscriptions directly in the admin order page!
## Important Information
 - ### WP Cerber
    If you are using WP Cerber for security you must go under WP Cerber settings on "Antispam" option, introduce the next sentence in the Query whitelist input box:

    ```
    wc-api=mbbxs_retry_execution
    wc-api=mobbex_subs_webhook
    ```