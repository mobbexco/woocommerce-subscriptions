<?php
namespace MobbexSubscription;
class ProductSettings
{
    /** @var \MobbexSubscription\Helper */
    public static $helper;

    public static function init()
    {
        // Load helpers
        self::$helper    = new \MobbexSubscription\Helper;
        $checkout_helper = new \Mobbex\WP\Checkout\Model\Helper;

        if ($checkout_helper->is_extension_ready()){
            if (self::$helper->is_wcs_active()){
                add_action('wp_after_insert_post', [self::class, 'create_mobbex_sub_integration_wcs']);
                add_action('woocommerce_product_options_general_product_data', [self::class, 'add_mobbex_custom_product_fields']);
                // Save custom fields when the product is saved
                add_action('woocommerce_process_product_meta', [self::class, 'save_mobbex_custom_product_fields']);
                return;
            }
            // Add/save subscription fields
            add_action('woocommerce_product_options_general_product_data', [self::class, 'add_subscription_fields']);
            add_action('woocommerce_process_product_meta', [self::class, 'save_subscription_fields']);

            // Add admin scripts
            add_action('add_subscription_admin_scripts', [self::class, 'load_scripts']);
        }
    }

    // Standalone integration

    /**
     * Display subscription fields in product admin general tab.
     */
    public static function add_subscription_fields()
    {
        echo '<div class="options_group show_if_simple"><h2>Mobbex Subscription options</h2>';

        self::subscription_mode_field();
        self::charge_interval_field();
        self::free_trial_field();
        self::signup_fee_field();
        self::test_mode_field();

        echo '</div>';
    }

    /**
     * Render Subscription Mode field.
     */
    public static function subscription_mode_field()
    {
        $field = [
            'id'          => 'mbbxs_subscription_mode',
            'value'       => \MobbexSubscription\Product::is_subscription(),
            'cbvalue'     => true,
            'label'       => __('Subscription Mode', 'mobbex-subs-for-woocommerce'), // Modo suscripcion / Modalidad de suscripción
            'description' => __('Mobbex process this product as a subscription', 'mobbex-subs-for-woocommerce'), // Mobbex procesará este producto a modo de suscripción
            'desc_tip'    => true,
        ];

        woocommerce_wp_checkbox($field);
    }

    /**
     * Render Charge Interval field.
     */
    public static function charge_interval_field()
    {
        $charge_interval = \MobbexSubscription\Product::get_charge_interval();
        $sub_type = self::$helper->is_wcs_active() ? 'manual' : 'dynamic';

        ?>
        <p class="form-field mbbxs_charge_interval_field hidden">
            <label for="mbbxs_charge_interval"><?= __('Charge every', 'mobbex-subs-for-woocommerce'); // Cobrar cada ?></label> 
            <span class="wrap two_fields_inline">
                <?php if ($sub_type === 'manual'): ?>
                    <input type="text" name="mbbxs_charge_interval_interval" id="mbbxs_charge_interval_interval" class="field" value="<?= $charge_interval['interval'] ?>">
                    <select name="mbbxs_charge_interval_period" id="mbbxs_charge_interval_period" class="field">
                        <option value="d" <?php selected('d', $charge_interval['period']) ?>><?= __('days', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="w" <?php selected('w', $charge_interval['period']) ?>><?= __('weeks', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="m" <?php selected('m', $charge_interval['period']) ?>><?= __('months', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="y" <?php selected('y', $charge_interval['period']) ?>><?= __('years', 'mobbex-subs-for-woocommerce') ?></option>
                    </select>
                <?php else: ?>
                    <select name="mbbxs_charge_interval_interval" id="mbbxs_charge_interval_interval" class="field">
                        <option value="1"  <?php selected('1', $charge_interval['interval'])  ?>><?= 1?></option>
                        <option value="2"  <?php selected('2', $charge_interval['interval'])  ?>><?= 2?></option>
                        <option value="3"  <?php selected('3', $charge_interval['interval'])  ?>><?= 3?></option>
                        <option value="6"  <?php selected('6', $charge_interval['interval'])  ?>><?= 6?></option>
                        <option value="7"  <?php selected('7', $charge_interval['interval'])  ?>><?= 7?></option>
                        <option value="15" <?php selected('15', $charge_interval['interval']) ?>><?= 15?></option>
                    </select>
                    <select name="mbbxs_charge_interval_period" id="mbbxs_charge_interval_period" class="field">
                        <option value="d" <?php selected('d', $charge_interval['period']) ?>><?= __('days', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="m" <?php selected('m', $charge_interval['period']) ?>><?= __('months', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="y" <?php selected('y', $charge_interval['period']) ?>><?= __('years', 'mobbex-subs-for-woocommerce') ?></option>
                    </select>
                <?php endif; ?>
            </span>
            <?= wc_help_tip(__('Interval and period in which the subscription will be charged', 'mobbex-subs-for-woocommerce')); // Intervalo y período en el que se cobrará la suscripción ?>
        </p>
        <?php
    }

    /**
     * Render Free Trial field.
     */
    public static function free_trial_field()
    {
        $free_trial = \MobbexSubscription\Product::get_free_trial();
        $sub_type   = self::$helper->is_wcs_active() ? 'manual' : 'dynamic';

        ?>
        <p class="form-field mbbxs_free_trial_field hidden">
            <label for="mbbxs_free_trial"><?= __('Free trial', 'mobbex-subs-for-woocommerce'); ?></label> <!-- Período de prueba -->
            <?php if ($sub_type === 'manual'): ?>
                <span class="wrap two_fields_inline">
                    <input type="text" name="mbbxs_free_trial_interval" id="mbbxs_free_trial_interval" class="field" value="<?= $free_trial['interval'] ?>">
                    <select name="mbbxs_free_trial_period" id="mbbxs_free_trial_period" class="field">
                        <option value="d" <?php selected('d', $free_trial['period']) ?>><?= __('days', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="w" <?php selected('w', $free_trial['period']) ?>><?= __('weeks', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="m" <?php selected('m', $free_trial['period']) ?>><?= __('months', 'mobbex-subs-for-woocommerce') ?></option>
                        <option value="y" <?php selected('y', $free_trial['period']) ?>><?= __('years', 'mobbex-subs-for-woocommerce') ?></option>
                    </select>
                </span>
            <?php else: ?>
                <span class="wrap">
                    <input type="text" name="mbbxs_free_trial_interval" id="mbbxs_free_trial_interval" class="field" value="<?= $free_trial['interval'] ?>">
                </span>
            <?php endif; ?>
            <?= wc_help_tip(__('Number of periods during which the subscription will not be charged. This setting does not affect the sign-up fee, which is still charged at the beginning of the subscription.', 'mobbex-subs-for-woocommerce')); ?> <!-- Cantidad de periodos durante los cuales no se cobrará la suscripción. Esta configuración no afecta a la tarifa de registro, que se sigue cobrando al comienzo de la suscripción. -->
        </p>
        <?php
    }

    /**
     * Render Sign-up fee field.
     */
    public static function signup_fee_field()
    {
        $field = [
            'id'            => 'mbbxs_signup_fee',
            'value'         => \MobbexSubscription\Product::get_signup_fee(),
            'cbvalue'       => false,
            'label'         => __('Sign-up fee', 'mobbex-subs-for-woocommerce'), // Tarifa de registro
            'description'   => __('Fee charged at subscription start', 'mobbex-subs-for-woocommerce'), // Tarifa cobrada al iniciar la suscripción
            'desc_tip'      => true,
            'wrapper_class' => 'hidden',
        ];

        woocommerce_wp_text_input($field);
    }

    /**
     * Render Subscription Test Mode field.
     */
    public static function test_mode_field()
    {
        $field = [
            'id'            => 'mbbxs_test_mode',
            'value'         => \MobbexSubscription\Product::get_test_mode(),
            'label'         => __('Mobbex Test Mode', 'mobbex-subs-for-woocommerce'), 
            'description'   => __('Set Mobbex Subscription test mode', 'mobbex-subs-for-woocommerce'),
            'desc_tip'      => true,
            'wrapper_class' => 'hidden',
        ];

        woocommerce_wp_checkbox($field);
    }

    /**
     * Save subscription fields from product admin.
     * 
     * @param int|string $post_id
     */
    public static function save_subscription_fields($post_id)
    {
        // Get product
        $product      = wc_get_product($post_id);
        $subscription = new \MobbexSubscription\Subscription;

        // Set possible periods for validation
        $possible_periods = ['d', 'w', 'm', 'y'];

        // Get and validate data
        $subscription_mode   = (!empty($_POST['mbbxs_subscription_mode']) && $_POST['mbbxs_subscription_mode'] === '1');
        $free_trial_interval = (!empty($_POST['mbbxs_free_trial_interval']) && is_numeric($_POST['mbbxs_free_trial_interval'])) ? (int) $_POST['mbbxs_free_trial_interval'] : 0;
        $free_trial_period   = (!empty($_POST['mbbxs_free_trial_period']) && in_array($_POST['mbbxs_free_trial_period'], $possible_periods)) ? esc_attr($_POST['mbbxs_free_trial_period']) : '';
        $charge_interval     = (!empty($_POST['mbbxs_charge_interval_interval']) && is_numeric($_POST['mbbxs_charge_interval_interval'])) ? (int) $_POST['mbbxs_charge_interval_interval'] : 1;
        $charge_period       = (!empty($_POST['mbbxs_charge_interval_period']) && in_array($_POST['mbbxs_charge_interval_period'], $possible_periods)) ? esc_attr($_POST['mbbxs_charge_interval_period']) : 'm';
        // TODO: Validate that interval and period work fine together in dynamic subscription mode
        
        $sub_options = [
            'post_id'    => $post_id,
            'type'       => 'dynamic',
            'name'       => $product->get_name(),
            'reference'  => "wc_order_{$post_id}",
            'trial'      => $free_trial_interval,
            'interval'   => $charge_interval . $charge_period,
            'price'      => isset($_POST['_regular_price']) ? (float) $_POST['_regular_price'] : 0,
            'test'       => isset($_POST['mbbxs_test_mode']) ? $_POST['mbbxs_test_mode'] : '',
            'signup_fee' => isset($_POST['mbbxs_signup_fee']) && is_numeric($_POST['mbbxs_signup_fee']) ? (float) $_POST['mbbxs_signup_fee'] : 0,
        ];

        //Create/update subscription.
        if (\MobbexSubscription\Product::is_subscription($post_id) || $subscription_mode)
            $subscription->create_mobbex_subscription($sub_options);
        
        // Save data
        update_post_meta($post_id, 'mbbxs_test_mode', $sub_options['test']);
        update_post_meta($post_id, 'mbbxs_signup_fee', $sub_options['signup_fee']);
        update_post_meta($post_id, 'mbbxs_free_trial', [
            'interval' => $free_trial_interval,
            'period'   => $free_trial_period,
        ]);
        update_post_meta($post_id, 'mbbxs_subscription_mode', $subscription_mode);
        update_post_meta($post_id, 'mbbxs_charge_interval', [
            'interval' => $charge_interval,
            'period'   => $charge_period,
        ]);
    }

    /**
     * Load styles and scripts for dynamic options.
     */
    public static function load_scripts()
    {
        wp_enqueue_style('mbbxs-product-style', MOBBEX_SUBS_URL . 'assets/css/subs-product-admin.css', null, MOBBEX_VERSION);
        wp_enqueue_script('mbbxs-product-js', MOBBEX_SUBS_URL . 'assets/js/subs-product-admin.js', null, MOBBEX_VERSION);
    }

    // WCS Integration

    /**
     * Create/Update Mobbex Subscription in wcs integration mode.
     * @param int|string $post_id
     */
    public static function create_mobbex_sub_integration_wcs($post_id)
    {
        // Get product
        $product      = wc_get_product($post_id);
        $subscription = new \MobbexSubscription\Subscription;

        // Checks if there is a subscription product
        if(!\WC_Subscriptions_Product::is_subscription($post_id))
            return;
        
        //sub options
        $sub_options = [
            'interval'   => '',
            'trial'      => '',
            'type'       => 'manual',
            'post_id'    => $post_id,
            'name'       => $product->get_name(),
            'reference'  => "wc_order_{$post_id}",
            'price'      => isset($_POST['_subscription_price']) ? $_POST['_subscription_price'] : 0,
            'signup_fee' => isset($_POST['_subscription_sign_up_fee']) ? $_POST['_subscription_sign_up_fee'] : 0,
            'test'       => isset($_POST['mobbex_subscription_test_mode']) ? $_POST['mobbex_subscription_test_mode'] : '',
        ];

        // Create/update subscription.
        $subscription->create_mobbex_subscription($sub_options);
    }

    /**
     * Add wcs subscription custom fields
     */
    public static function add_mobbex_custom_product_fields()
    {
        echo '<div class="product_custom_fields">';
    
        // Mobbex Subscription test mode
        woocommerce_wp_checkbox(
            array(
                'id'          => 'mobbex_subscription_test_mode',
                'label'       => __('Mobbex Test Mode', 'woocommerce'),
                'description' => __('Enable/Disable setting the Mobbex Subscription in test mode', 'woocommerce'),
                'value'       => get_post_meta(get_the_ID(), 'mobbex_subscription_test_mode', true),
            )
        );
    
        echo '</div>';
    }

    /**
     * Save wcs subscription custom fields
     */
    public static function save_mobbex_custom_product_fields($product_id)
    {
        // Save Mobbex Subscription test mode
        $checkbox_value = isset($_POST['mobbex_subscription_test_mode']) ? 'yes' : 'no';
        update_post_meta($product_id, 'mobbex_subscription_test_mode', $checkbox_value);
    }
}