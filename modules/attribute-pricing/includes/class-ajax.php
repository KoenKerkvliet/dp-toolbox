<?php
defined('ABSPATH') || exit;

class DP_AP_Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_dp_ap_get_attribute',         [$this, 'get_attribute']);
        add_action('wp_ajax_dp_ap_check_attribute',       [$this, 'check_attribute']);
        add_action('wp_ajax_dp_ap_add_term_to_attribute', [$this, 'add_term_to_attribute']);
    }

    private function authorize($action)
    {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(__('Not allowed', 'dp-attribute-pricing'), 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(__('Invalid nonce', 'dp-attribute-pricing'), 403);
        }
    }

    public function get_attribute()
    {
        $this->authorize('dp_ap_get_attribute');

        $slug     = isset($_POST['attribute_slug']) ? sanitize_title(wp_unslash($_POST['attribute_slug'])) : '';
        $taxonomy = 'pa_' . $slug;

        if ($slug === '' || !taxonomy_exists($taxonomy)) {
            wp_send_json_error(__('Unknown attribute', 'dp-attribute-pricing'), 404);
        }

        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            wp_send_json_error($terms->get_error_message(), 400);
        }

        $out = array_map(function ($t) {
            return ['term_id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
        }, $terms);

        wp_send_json_success($out);
    }

    public function check_attribute()
    {
        $this->authorize('dp_ap_check_attribute');

        $slug     = isset($_POST['attribute_name']) ? sanitize_title(wp_unslash($_POST['attribute_name'])) : '';
        $taxonomy = 'pa_' . $slug;

        if ($slug === '' || !taxonomy_exists($taxonomy)) {
            wp_send_json_error(__('Unknown attribute', 'dp-attribute-pricing'), 404);
        }

        $used = isset($_POST['attributes']) && is_array($_POST['attributes'])
            ? array_map('absint', wp_unslash($_POST['attributes']))
            : [];

        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            wp_send_json_error($terms->get_error_message(), 400);
        }

        $available = array_values(array_filter($terms, function ($t) use ($used) {
            return !in_array((int) $t->term_id, $used, true);
        }));

        $out = array_map(function ($t) {
            return ['term_id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
        }, $available);

        wp_send_json_success($out);
    }

    public function add_term_to_attribute()
    {
        $this->authorize('dp_ap_add_term_to_attribute');

        $slug     = isset($_POST['attribute']) ? sanitize_title(wp_unslash($_POST['attribute'])) : '';
        $taxonomy = 'pa_' . $slug;
        $name     = isset($_POST['attribute_name']) ? sanitize_text_field(wp_unslash($_POST['attribute_name'])) : '';

        if ($slug === '' || $name === '' || !taxonomy_exists($taxonomy)) {
            wp_send_json_error(__('Invalid request', 'dp-attribute-pricing'), 400);
        }

        $inserted = wp_insert_term($name, $taxonomy);
        if (is_wp_error($inserted)) {
            wp_send_json_error($inserted->get_error_message(), 400);
        }

        $term = get_term($inserted['term_id']);
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(__('Term created but could not be loaded', 'dp-attribute-pricing'), 500);
        }

        wp_send_json_success([
            'attribute_name'             => $term->name,
            'attribute_slug'             => $term->slug,
            'attribute_value'            => (int) $term->term_id,
            'woocommerce_attribute_name' => $term->taxonomy,
        ]);
    }
}
