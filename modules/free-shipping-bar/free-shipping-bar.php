<?php
/**
 * Module Name: Free Shipping Bar
 * Description: Toont een voortgangsbalk in de cart en mini-cart: "Nog €X tot gratis verzending". Auto-detecteert de drempel uit je WooCommerce verzendzones (free_shipping methode). Power-users kunnen overriden via het filter dp_fsb_threshold.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('DP_FSB_VERSION', '1.0.0');
define('DP_FSB_PATH', __DIR__ . '/');
define('DP_FSB_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    add_action('woocommerce_before_cart',      'dp_fsb_render', 5);
    add_action('woocommerce_before_mini_cart', 'dp_fsb_render', 5);

    add_action('wp_enqueue_scripts', function () {
        if (!is_cart() && !is_checkout() && !is_shop() && !is_product() && !is_product_category() && !is_product_tag()) return;
        $css = DP_FSB_PATH . 'assets/css/frontend.css';
        wp_enqueue_style(
            'dp-fsb-frontend',
            DP_FSB_URL . 'assets/css/frontend.css',
            [],
            file_exists($css) ? filemtime($css) : DP_FSB_VERSION
        );
    });
}, 20);

/**
 * Detect the free-shipping threshold from active WC shipping zones.
 * Returns the lowest min_amount across applicable zones, or 0 if none found.
 * Override with the dp_fsb_threshold filter.
 */
function dp_fsb_get_threshold() {
    if (!WC()->cart) return 0;

    $packages  = WC()->cart->get_shipping_packages();
    $threshold = 0;

    foreach ($packages as $package) {
        if (!function_exists('wc_get_shipping_zone')) continue;
        $zone = wc_get_shipping_zone($package);
        if (!$zone) continue;
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->id !== 'free_shipping') continue;
            if (empty($method->min_amount)) continue;
            $amount = (float) $method->min_amount;
            $threshold = $threshold === 0 ? $amount : min($threshold, $amount);
        }
    }

    return (float) apply_filters('dp_fsb_threshold', $threshold);
}

function dp_fsb_render() {
    if (!WC()->cart || WC()->cart->is_empty()) return;

    $threshold = dp_fsb_get_threshold();
    if ($threshold <= 0) return;

    $subtotal  = (float) WC()->cart->get_displayed_subtotal();
    if (WC()->cart->display_prices_including_tax()) {
        // Most NL shops display incl tax; subtotal helper handles both.
    }
    $remaining = max(0, $threshold - $subtotal);
    $progress  = $threshold > 0 ? min(100, ($subtotal / $threshold) * 100) : 0;
    $reached   = $remaining <= 0;

    echo '<div class="dp-fsb' . ($reached ? ' dp-fsb--reached' : '') . '">';
    echo '<div class="dp-fsb__message">';
    if ($reached) {
        echo '<span class="dp-fsb__icon">🎉</span> ';
        esc_html_e('Je krijgt gratis verzending!', 'dp-toolbox');
    } else {
        printf(
            /* translators: %s: remaining amount, formatted */
            esc_html__('Nog %s tot gratis verzending', 'dp-toolbox'),
            wp_kses_post(wc_price($remaining))
        );
    }
    echo '</div>';
    echo '<div class="dp-fsb__track"><div class="dp-fsb__fill" style="width:' . esc_attr($progress) . '%"></div></div>';
    echo '</div>';
}
