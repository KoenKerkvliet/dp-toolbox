<?php
/**
 * Module Name: Attribute Pricing
 * Description: Voegt een "Extra options" tab toe aan simpele WooCommerce-producten. Per attribuut-waarde een meerprijs zonder dat je variaties hoeft op te tuigen.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'DP_AP_VERSION', '1.0.0' );
define( 'DP_AP_PATH', __DIR__ . '/' );
define( 'DP_AP_URL', plugin_dir_url( __FILE__ ) );
define( 'DP_AP_META_KEY', 'dp_attribute_pricing' );

/* HPOS compat declaration (no-op when WC is missing). */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            DP_TOOLBOX_PATH . 'dp-toolbox.php',
            true
        );
    }
} );

/* Bootstrap on plugins_loaded — bail with admin notice if WC is missing. */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            $screen = get_current_screen();
            if ( ! $screen || $screen->id !== 'toplevel_page_dp-toolbox' ) return;
            echo '<div class="notice notice-warning"><p><strong>DP Toolbox — Attribute Pricing:</strong> module is actief maar WooCommerce ontbreekt. De tab "Extra options" verschijnt pas zodra je WooCommerce installeert en activeert.</p></div>';
        } );
        return;
    }

    require_once DP_AP_PATH . 'includes/class-admin-tab.php';
    require_once DP_AP_PATH . 'includes/class-save-product.php';
    require_once DP_AP_PATH . 'includes/class-single-product.php';
    require_once DP_AP_PATH . 'includes/class-cart-flow.php';
    require_once DP_AP_PATH . 'includes/class-loop-price.php';
    require_once DP_AP_PATH . 'includes/class-ajax.php';

    new DP_AP_Admin_Tab();
    new DP_AP_Save_Product();
    new DP_AP_Single_Product();
    new DP_AP_Cart_Flow();
    new DP_AP_Loop_Price();
    new DP_AP_Ajax();
}, 20 );
