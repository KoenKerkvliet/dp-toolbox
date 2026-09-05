<?php
/**
 * Module Name: Low Stock Urgency
 * Description: Toont "Nog X stuks beschikbaar!" op de single-product pagina zodra de voorraad onder de WooCommerce low-stock drempel zakt. Geen instellingen — leest de per-product of globale drempel uit WooCommerce. Filter dp_lsu_threshold om de drempel te overriden.
 * Category: ecommerce
 * Requires: woocommerce
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('DP_LSU_VERSION', '1.0.0');
define('DP_LSU_PATH', __DIR__ . '/');
define('DP_LSU_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    add_action('woocommerce_before_add_to_cart_form', 'dp_lsu_render', 5);

    add_action('wp_enqueue_scripts', function () {
        if (!is_product()) return;
        $css = DP_LSU_PATH . 'assets/css/frontend.css';
        wp_enqueue_style(
            'dp-lsu-frontend',
            DP_LSU_URL . 'assets/css/frontend.css',
            [],
            file_exists($css) ? filemtime($css) : DP_LSU_VERSION
        );
    });
}, 20);

function dp_lsu_render() {
    global $product;
    if (!$product instanceof WC_Product) return;
    if (!$product->managing_stock()) return;
    if (!$product->is_in_stock()) return;

    $qty = (int) $product->get_stock_quantity();
    if ($qty <= 0) return;

    $threshold = (int) $product->get_low_stock_amount();
    if ($threshold <= 0) {
        $threshold = (int) get_option('woocommerce_notify_low_stock_amount', 5);
    }
    $threshold = (int) apply_filters('dp_lsu_threshold', $threshold, $product);

    if ($qty > $threshold) return;

    echo '<div class="dp-lsu">';
    echo '<span class="dp-lsu__icon" aria-hidden="true">⚡</span>';
    echo '<span class="dp-lsu__text">';
    printf(
        wp_kses(
            _n(
                'Nog %s stuk beschikbaar!',
                'Nog %s stuks beschikbaar!',
                $qty,
                'dp-toolbox'
            ),
            ['strong' => []]
        ),
        '<strong>' . esc_html($qty) . '</strong>'
    );
    echo '</span>';
    echo '</div>';
}
