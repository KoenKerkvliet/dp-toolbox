<?php
defined('ABSPATH') || exit;

class DP_AP_Cart_Flow
{
    public function __construct()
    {
        add_filter('woocommerce_add_cart_item_data', [$this, 'capture_cart_item_data'], 25, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_in_cart'], 25, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_price'], 25, 1);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);
    }

    public function capture_cart_item_data($cart_item_data, $product_id)
    {
        if (empty($_POST['dp_ap']) || !is_array($_POST['dp_ap'])) return $cart_item_data;

        $product_meta = get_post_meta($product_id, DP_AP_META_KEY, true);
        if (!is_array($product_meta) || empty($product_meta)) return $cart_item_data;

        $selected = [];
        $total    = 0.0;
        $raw      = wp_unslash($_POST['dp_ap']);

        foreach ($raw as $taxonomy => $term_id) {
            $taxonomy = sanitize_text_field($taxonomy);
            $term_id  = absint($term_id);
            if (!$term_id || !isset($product_meta[$taxonomy])) continue;

            $match = null;
            foreach ($product_meta[$taxonomy] as $slug => $row) {
                if ((int) $row['attribute_id'] === $term_id) {
                    $match = $row;
                    break;
                }
            }
            if (!$match) continue;

            $term    = get_term($term_id);
            $tax_obj = get_taxonomy($taxonomy);
            if (!$term || is_wp_error($term) || !$tax_obj) continue;

            $price = $match['attribute_price'] !== '' ? (float) $match['attribute_price'] : 0;

            $selected[] = [
                'attribute_taxonomy' => $taxonomy,
                'attribute_label'    => $tax_obj->labels->singular_name,
                'value_label'        => $term->name,
                'value_term_id'      => (int) $term->term_id,
                'value_price'        => $price,
            ];
            $total += $price;
        }

        if (!empty($selected)) {
            $cart_item_data['dp_ap']       = $selected;
            $cart_item_data['dp_ap_price'] = (float) $total;
        }

        return $cart_item_data;
    }

    public function apply_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!$cart instanceof WC_Cart) return;

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['dp_ap_price'])) continue;

            $base_product = wc_get_product($cart_item['data']->get_id());
            if (!$base_product) continue;

            $base_price = (float) $base_product->get_price();
            $cart_item['data']->set_price($base_price + (float) $cart_item['dp_ap_price']);
        }
    }

    public function display_in_cart($cart_data, $cart_item)
    {
        if (empty($cart_item['dp_ap']) || !is_array($cart_item['dp_ap'])) return $cart_data;

        foreach ($cart_item['dp_ap'] as $sel) {
            $display = $sel['value_label'];
            if ($sel['value_price'] > 0) {
                $display .= ' ( + ' . wc_price($sel['value_price']) . ' )';
            }
            $cart_data[] = [
                'name'    => $sel['attribute_label'],
                'display' => $display,
            ];
        }

        return $cart_data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values, $order)
    {
        if (empty($values['dp_ap']) || !is_array($values['dp_ap'])) return;

        foreach ($values['dp_ap'] as $sel) {
            $display = $sel['value_label'];
            if ($sel['value_price'] > 0) {
                $price_text = html_entity_decode(wp_strip_all_tags(wc_price($sel['value_price'])), ENT_QUOTES, 'UTF-8');
                $display   .= ' ( + ' . $price_text . ' )';
            }
            $item->add_meta_data($sel['attribute_label'], $display);
        }
    }
}
