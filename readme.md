# Mobbex Subscriptions <span style="font-size:16px;">for WooCommerce</span>
Plugin that integrates Mobbex Subscriptions functionality to WooCommerce and WooCommerce Subscriptions.
## Requirements
- Wordpress >= 5.0
- WooCommerce >= 3.5.2
- WooCommerce Subscriptions >= 2.0 - 6.3.2 *(only for WooCommerce Subscriptions integration mode)*
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
