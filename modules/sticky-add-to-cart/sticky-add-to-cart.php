<?php
/**
 * Module Name: Sticky Add to Cart
 * Description: Op mobiel: zodra de gebruiker voorbij de add-to-cart knop scrollt verschijnt onderaan een vaste bar met productafbeelding, naam, prijs en een Toevoegen-knop. Tap-to-add — submit van de bestaande product-form (inclusief eventuele attribute-selecties).
 * Category: ecommerce
 * Requires: woocommerce
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('DP_SAC_VERSION', '1.0.0');
define('DP_SAC_PATH', __DIR__ . '/');
define('DP_SAC_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    add_action('wp_enqueue_scripts', 'dp_sac_enqueue');
    add_action('wp_footer',          'dp_sac_render');
}, 20);

function dp_sac_enqueue() {
    if (!is_product()) return;

    $css = DP_SAC_PATH . 'assets/css/frontend.css';
    $js  = DP_SAC_PATH . 'assets/js/frontend.js';

    wp_enqueue_style(
        'dp-sac-frontend',
        DP_SAC_URL . 'assets/css/frontend.css',
        [],
        file_exists($css) ? filemtime($css) : DP_SAC_VERSION
    );
    wp_enqueue_script(
        'dp-sac-frontend',
        DP_SAC_URL . 'assets/js/frontend.js',
        [],
        file_exists($js) ? filemtime($js) : DP_SAC_VERSION,
        true
    );
}

function dp_sac_render() {
    if (!is_product()) return;

    global $product;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof WC_Product) return;

    $thumb_id = $product->get_image_id();
    $thumb    = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

    $purchasable = $product->is_purchasable() && $product->is_in_stock();
    ?>
    <div class="dp-sac" aria-hidden="true">
        <div class="dp-sac__inner">
            <?php if ($thumb) : ?>
                <img class="dp-sac__thumb" src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy">
            <?php endif; ?>
            <div class="dp-sac__info">
                <span class="dp-sac__name"><?php echo esc_html($product->get_name()); ?></span>
                <span class="dp-sac__price"><?php echo wp_kses_post($product->get_price_html()); ?></span>
            </div>
            <?php if ($purchasable) : ?>
                <button type="button" class="dp-sac__btn">
                    <?php echo esc_html__('Toevoegen', 'dp-toolbox'); ?>
                </button>
            <?php else : ?>
                <span class="dp-sac__out"><?php echo esc_html__('Niet leverbaar', 'dp-toolbox'); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
