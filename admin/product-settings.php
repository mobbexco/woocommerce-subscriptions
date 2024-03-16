<?php

class Mbbx_Subs_Product_Settings
{
    /** @var Mbbxs_Helper */
    public static $helper;

    public static function init()
    {
        // Load helper
        self::$helper = new Mbbxs_Helper;

        if(self::$helper->is_wcs_active()){
            add_action('wp_after_insert_post', [self::class, 'create_mobbex_sub_integration_wcs']);
            return;
        }

        // Add/save subscription fields
		add_action('woocommerce_product_options_general_product_data', [self::class, 'add_subscription_fields']);
		add_action('woocommerce_process_product_meta', [self::class, 'save_subscription_fields']);

        // Add some scripts
        add_action('admin_enqueue_scripts', [self::class, 'load_scripts']);
    }

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

        echo '</div>';
    }

    /**
     * Render Subscription Mode field.
     */
    public static function subscription_mode_field()
    {
        $field = [
            'id'          => 'mbbxs_subscription_mode',
            'value'       => Mbbx_Subs_Product::is_subscription(),
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
        $charge_interval = Mbbx_Subs_Product::get_charge_interval();
        $sub_type = isset(self::$helper->type) ? self::$helper->type : 'dynamic';

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
        $free_trial = Mbbx_Subs_Product::get_free_trial();
        $sub_type   = isset(self::$helper->type) ? self::$helper->type : 'dynamic';

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
            'value'         => Mbbx_Subs_Product::get_signup_fee(),
            'label'         => __('Sign-up fee', 'mobbex-subs-for-woocommerce'), // Tarifa de registro
            'description'   => __('Fee charged at subscription start', 'mobbex-subs-for-woocommerce'), // Tarifa cobrada al iniciar la suscripción
            'desc_tip'      => true,
            'wrapper_class' => 'hidden',
        ];

        woocommerce_wp_text_input($field);
    }

    /**
     * Save subscription fields from product admin.
     * 
     * @param int|string $post_id
     */
    public static function save_subscription_fields($post_id)
    {
        //get product
        $product = wc_get_product($post_id);

        // Set possible periods for validation
        $possible_periods = ['d', 'w', 'm', 'y'];

        // Get and validate data
        $subscription_mode   = (!empty($_POST['mbbxs_subscription_mode']) && $_POST['mbbxs_subscription_mode'] === '1');
        $charge_interval     = (!empty($_POST['mbbxs_charge_interval_interval']) && is_numeric($_POST['mbbxs_charge_interval_interval'])) ? (int) $_POST['mbbxs_charge_interval_interval'] : 1;
        $charge_period       = (!empty($_POST['mbbxs_charge_interval_period']) && in_array($_POST['mbbxs_charge_interval_period'], $possible_periods)) ? esc_attr($_POST['mbbxs_charge_interval_period']) : 'm';
        $free_trial_interval = (!empty($_POST['mbbxs_free_trial_interval']) && is_numeric($_POST['mbbxs_free_trial_interval'])) ? (int) $_POST['mbbxs_free_trial_interval'] : 0;
        $free_trial_period   = (!empty($_POST['mbbxs_free_trial_period']) && in_array($_POST['mbbxs_free_trial_period'], $possible_periods)) ? esc_attr($_POST['mbbxs_free_trial_period']) : '';
        $signup_fee          = (!empty($_POST['mbbxs_signup_fee']) && is_numeric($_POST['mbbxs_signup_fee'])) ? (int) $_POST['mbbxs_signup_fee'] : 0;
        // TODO: Validate that interval and period work fine together in dynamic subscription mode
        
        //sub options
        $sub_options = [
            'type'      => 'dynamic',
            'interval'  => $charge_interval . $charge_period,
            'trial'     => $free_trial_interval,
            'setup_fee' => $signup_fee,
            'post_id'   => $post_id,
            'reference' => "wc_order_{$post_id}",
            'price'     => $product->get_price(),
            'name'      => $product->get_name(),
        ];

        //Create/update subscription.
        if(Mbbx_Subs_Product::is_subscription($post_id))
            $subscription = $sub_options['type'] === 'dynamic' ? \MobbexSubscription::create_mobbex_subscription($sub_options) : \MobbexSubscription::create_mobbex_subscription($sub_options);

        // Save data
        update_post_meta($post_id, 'mbbxs_subscription_mode', $subscription_mode);
        update_post_meta($post_id, 'mbbxs_charge_interval', [
            'interval' => $charge_interval,
            'period'   => $charge_period,
        ]);
        update_post_meta($post_id, 'mbbxs_free_trial', [
            'interval' => $free_trial_interval,
            'period'   => $free_trial_period,
        ]);
        update_post_meta($post_id, 'mbbxs_signup_fee', $signup_fee);
    }

    /**
     * Create/Update Mobbex Subscription in wcs integration mode.
     * @param int|string $post_id
     */
    public static function create_mobbex_sub_integration_wcs($post_id)
    {
        // get product
        $product = wc_get_product($post_id);

        // Checks if there is a subscription product
        if(!WC_Subscriptions_Product::is_subscription($post_id))
            return;
        
        //sub options
        $sub_options = [
            'type'      => 'manual',
            'interval'  => '',
            'trial'     => '',
            'setup_fee' => isset($_POST['_subscription_sign_up_fee']) ? $_POST['_subscription_sign_up_fee'] : 0,
            'post_id'   => $post_id,
            'reference' => "wc_order_{$post_id}",
            'price'     => isset($_POST['_subscription_price']) ? $_POST['_subscription_price'] : 0,
            'name'      => $product->get_name(),
        ];

        //Create/update subscription.
        \MobbexSubscription::create_mobbex_subscription($sub_options);
    }

    /**
     * Load styles and scripts for dynamic options.
     */
    public static function load_scripts($hook)
    {
        global $post;

        if (($hook == 'post-new.php' || $hook == 'post.php') && $post->post_type == 'product') {
            wp_enqueue_style('mbbxs-product-style', plugin_dir_url(__FILE__) . '../../assets/css/product-admin.css');
            wp_enqueue_script('mbbxs-product', plugin_dir_url(__FILE__) . '../../assets/js/product-admin.js');
        }
    }
}