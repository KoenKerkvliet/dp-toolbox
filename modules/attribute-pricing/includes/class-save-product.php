<?php
defined('ABSPATH') || exit;

class DP_AP_Save_Product
{
    public function __construct()
    {
        add_action('woocommerce_update_product', [$this, 'save_meta'], 10, 1);
    }

    public function save_meta($product_id)
    {
        $is_quick_edit = (
            defined('DOING_AJAX') && DOING_AJAX &&
            isset($_REQUEST['action']) && $_REQUEST['action'] === 'inline-save'
        );
        if ($is_quick_edit) return;

        if (!isset($_POST['dp_ap_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dp_ap_nonce'])), 'dp_ap_save_meta')) return;

        if (empty($_POST['dp_ap']) || !is_array($_POST['dp_ap'])) {
            update_post_meta($product_id, DP_AP_META_KEY, '');
            DP_AP_Sync_To_WC::sync_product($product_id);
            return;
        }

        $sanitized = [];
        $raw = wp_unslash($_POST['dp_ap']);

        foreach ($raw as $taxonomy => $rows) {
            $taxonomy = sanitize_text_field($taxonomy);
            if (!taxonomy_exists($taxonomy)) continue;
            if (!is_array($rows)) continue;

            foreach ($rows as $term_slug => $row) {
                $term_slug = sanitize_title($term_slug);
                if ($term_slug === '') continue;

                $term_id = isset($row['attribute_id']) ? absint($row['attribute_id']) : 0;
                if (!$term_id) continue;

                $price_raw = isset($row['attribute_price']) ? trim((string) $row['attribute_price']) : '';
                $price     = $price_raw === '' ? '' : (float) $price_raw;

                $sanitized[$taxonomy][$term_slug] = [
                    'attribute_id'    => $term_id,
                    'attribute_price' => $price,
                ];
            }
        }

        update_post_meta($product_id, DP_AP_META_KEY, $sanitized);
        DP_AP_Sync_To_WC::sync_product($product_id);
    }
}
