<?php
defined('ABSPATH') || exit;

class DP_AP_Single_Product
{
    public function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_selects']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets()
    {
        if (!function_exists('is_product') || !is_product()) return;

        global $product;
        if (!$product instanceof WC_Product) {
            $product = wc_get_product(get_the_ID());
        }
        if (!$product instanceof WC_Product) return;

        $saved = get_post_meta($product->get_id(), DP_AP_META_KEY, true);
        if (!is_array($saved) || empty($saved)) return;

        $css_path = DP_AP_PATH . 'assets/css/frontend.css';
        $js_path  = DP_AP_PATH . 'assets/js/frontend.js';

        wp_enqueue_style(
            'dp-ap-frontend',
            DP_AP_URL . 'assets/css/frontend.css',
            [],
            file_exists($css_path) ? filemtime($css_path) : DP_AP_VERSION
        );
        wp_enqueue_script(
            'dp-ap-frontend',
            DP_AP_URL . 'assets/js/frontend.js',
            [],
            file_exists($js_path) ? filemtime($js_path) : DP_AP_VERSION,
            true
        );

        wp_localize_script('dp-ap-frontend', 'DPAttributePricing', [
            'basePrice'   => (float) $product->get_price(),
            'currency'    => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            'decimalSep'  => wc_get_price_decimal_separator(),
            'thousandSep' => wc_get_price_thousand_separator(),
            'decimals'    => (int) wc_get_price_decimals(),
            'symbolPos'   => get_option('woocommerce_currency_pos', 'left'),
        ]);
    }

    public function render_selects()
    {
        global $product;
        if (!$product instanceof WC_Product) return;

        $saved = get_post_meta($product->get_id(), DP_AP_META_KEY, true);
        if (!is_array($saved) || empty($saved)) return;

        echo '<div class="dp-ap-options">';

        foreach ($saved as $taxonomy_name => $rows) {
            if (!is_array($rows) || empty($rows)) continue;

            $tax_obj = get_taxonomy($taxonomy_name);
            if (!$tax_obj) continue;

            $field_id = 'dp-ap-' . sanitize_html_class($taxonomy_name);

            echo '<div class="dp-ap-options__row">';

            printf(
                '<label for="%s" class="dp-ap-options__label">%s</label>',
                esc_attr($field_id),
                esc_html($tax_obj->labels->singular_name)
            );

            printf(
                '<select id="%s" class="dp-ap-options__select" name="dp_ap[%s]">',
                esc_attr($field_id),
                esc_attr($taxonomy_name)
            );

            foreach ($rows as $row) {
                $term_id = isset($row['attribute_id']) ? (int) $row['attribute_id'] : 0;
                $term    = $term_id ? get_term($term_id) : null;
                if (!$term || is_wp_error($term)) continue;

                $price = isset($row['attribute_price']) && $row['attribute_price'] !== '' ? (float) $row['attribute_price'] : 0;

                printf(
                    '<option value="%s" data-price="%s">%s</option>',
                    esc_attr($term->term_id),
                    esc_attr($price),
                    esc_html($term->name)
                );
            }

            echo '</select>';
            echo '<span class="dp-ap-options__surcharge" aria-live="polite"></span>';

            echo '</div>';
        }

        echo '</div>';
    }
}
