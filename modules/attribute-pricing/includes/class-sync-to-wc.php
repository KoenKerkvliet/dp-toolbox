<?php
defined('ABSPATH') || exit;

/**
 * Mirror DP attribute-pricing meta → WC native term assignments + `_product_attributes` meta.
 *
 * JSF (and WC's own filtering, layered nav, etc.) reads from the native side. Without this
 * mirror, terms configured in the DP "Extra options" tab are invisible to filters because
 * `dp_attribute_pricing` is a separate meta key WC's APIs don't know about.
 *
 * Per-taxonomy mirror — DP is source of truth for taxonomies it touches. Taxonomies NOT in
 * DP meta are left untouched (preserves attributes set in WC's native attribuut-tab).
 */
class DP_AP_Sync_To_WC
{
    public static function sync_product($product_id)
    {
        $product_id = absint($product_id);
        if (!$product_id) return;

        $dp_meta = get_post_meta($product_id, DP_AP_META_KEY, true);
        $dp_meta = is_array($dp_meta) ? $dp_meta : [];

        // Step A — replace term-relationships per DP-mentioned taxonomy.
        foreach ($dp_meta as $tax => $rows) {
            $tax = sanitize_text_field($tax);
            if (!taxonomy_exists($tax)) continue;
            if (!is_array($rows)) continue;

            $slugs = [];
            foreach ($rows as $slug => $_row) {
                $slug = sanitize_title($slug);
                if ($slug !== '') $slugs[] = $slug;
            }
            wp_set_post_terms($product_id, $slugs, $tax, false);
        }

        // Step B — merge into `_product_attributes` (additive: keeps non-DP entries).
        $existing = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($existing)) $existing = [];
        $merged = $existing;
        $position = 0;

        foreach ($dp_meta as $tax => $rows) {
            $tax = sanitize_text_field($tax);
            if (!taxonomy_exists($tax)) continue;

            if (!is_array($rows) || empty($rows)) {
                unset($merged[$tax]);
                continue;
            }

            $merged[$tax] = [
                'name'         => $tax,
                'value'        => '',
                'position'     => isset($existing[$tax]['position']) ? (int) $existing[$tax]['position'] : $position,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ];
            $position++;
        }

        update_post_meta($product_id, '_product_attributes', $merged);

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        clean_post_cache($product_id);
    }

    /**
     * One-shot bulk sync — useful after first install of this feature or after manual
     * meta edits. Iterates all products with DP_AP_META_KEY set and mirrors each.
     * Returns count of products synced.
     */
    public static function sync_all()
    {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'meta_key'       => DP_AP_META_KEY,
            'meta_compare'   => 'EXISTS',
        ]);
        foreach ($ids as $pid) {
            self::sync_product($pid);
        }
        return count($ids);
    }
}
