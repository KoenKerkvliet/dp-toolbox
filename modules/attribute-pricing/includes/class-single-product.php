<?php
defined('ABSPATH') || exit;

class DP_AP_Single_Product
{
    public function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_selects']);
    }

    public function render_selects()
    {
        global $product;
        if (!$product instanceof WC_Product) return;

        $saved = get_post_meta($product->get_id(), DP_AP_META_KEY, true);
        if (!is_array($saved) || empty($saved)) return;

        echo '<table class="variations dp-ap-variations" cellspacing="0" role="presentation"><tbody>';
        foreach ($saved as $taxonomy_name => $rows) {
            if (!is_array($rows) || empty($rows)) continue;

            $tax_obj = get_taxonomy($taxonomy_name);
            if (!$tax_obj) continue;

            $field_id = 'dp-ap-' . sanitize_html_class($taxonomy_name);

            printf(
                '<tr><th class="label"><label for="%1$s">%2$s</label></th><td class="value"><select id="%1$s" class="dp-ap-select" name="dp_ap[%3$s]">',
                esc_attr($field_id),
                esc_html($tax_obj->labels->singular_name),
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

            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
    }
}
