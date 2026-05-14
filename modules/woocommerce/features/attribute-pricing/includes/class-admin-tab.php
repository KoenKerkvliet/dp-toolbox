<?php
defined('ABSPATH') || exit;

class DP_AP_Admin_Tab
{
    public function __construct()
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'register_tab'], 20);
        add_action('woocommerce_product_data_panels', [$this, 'render_panel'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_tab($tabs)
    {
        $tabs['dp_attribute_pricing'] = [
            'label'    => __('Extra options', 'dp-attribute-pricing'),
            'target'   => 'dp_attribute_pricing_panel',
            'class'    => [],
            'priority' => 70,
        ];
        return $tabs;
    }

    public function render_panel()
    {
        global $post;
        if (!$post) return;

        $saved = get_post_meta($post->ID, DP_AP_META_KEY, true);
        if (!is_array($saved)) $saved = [];

        $taxonomies = wc_get_attribute_taxonomies();

        echo '<div id="dp_attribute_pricing_panel" class="panel woocommerce_options_panel dp-ap-panel">';

        wp_nonce_field('dp_ap_save_meta', 'dp_ap_nonce');

        echo '<div class="dp-ap__picker">';
        echo '<p class="form-field"><label for="dp-ap-select">' . esc_html__('Select attribute', 'dp-attribute-pricing') . '</label>';
        echo '<select id="dp-ap-select" class="select short">';
        echo '<option value="">' . esc_html__('Select attribute', 'dp-attribute-pricing') . '</option>';
        foreach ($taxonomies as $tax) {
            $taxonomy_name = 'pa_' . $tax->attribute_name;
            $disabled = isset($saved[$taxonomy_name]) ? ' disabled' : '';
            printf(
                '<option value="%s" data-slug="%s"%s>%s</option>',
                esc_attr($tax->attribute_id),
                esc_attr($tax->attribute_name),
                $disabled,
                esc_html($tax->attribute_label)
            );
        }
        echo '</select></p>';
        echo '<button type="button" class="button button-primary dp-ap__add-attribute">' . esc_html__('Add', 'dp-attribute-pricing') . '</button>';
        echo '</div>';

        echo '<div class="dp-ap__wrapper">';
        foreach ($saved as $taxonomy_name => $rows) {
            $this->render_attribute_block($taxonomy_name, (array) $rows);
        }
        echo '</div>';

        echo '</div>';
    }

    private function render_attribute_block($taxonomy_name, $rows)
    {
        $tax_obj = get_taxonomy($taxonomy_name);
        if (!$tax_obj) return;

        $attribute_id    = wc_attribute_taxonomy_id_by_name($taxonomy_name);
        $attribute_label = $tax_obj->labels->singular_name;
        $attribute_slug  = str_replace('pa_', '', $taxonomy_name);

        printf(
            '<div class="dp-ap__attribute" data-attribute-name="%s" data-attribute-id="%s">',
            esc_attr($attribute_slug),
            esc_attr($attribute_id)
        );

        echo '<div class="dp-ap__attribute-head">';
        echo '<strong>' . esc_html($attribute_label) . '</strong>';
        echo '<button type="button" class="dp-ap__remove-attribute" aria-label="' . esc_attr__('Remove attribute', 'dp-attribute-pricing') . '">x</button>';
        echo '</div>';

        echo '<table class="dp-ap__table"><thead><tr>';
        echo '<th>' . esc_html__('Value', 'dp-attribute-pricing') . '</th>';
        echo '<th>' . esc_html__('Additional price', 'dp-attribute-pricing') . '</th>';
        echo '<th>' . esc_html__('Action', 'dp-attribute-pricing') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $term_slug => $row) {
            $term_id = isset($row['attribute_id']) ? (int) $row['attribute_id'] : 0;
            $term    = $term_id ? get_term($term_id) : null;
            if (!$term || is_wp_error($term)) continue;

            $price = isset($row['attribute_price']) ? $row['attribute_price'] : '';

            printf(
                '<tr><td>
                    <input type="hidden" readonly value="%1$s" name="dp_ap[%2$s][%3$s][attribute_id]">
                    <span data-attribute-id="%1$s" data-slug="%3$s">%4$s</span>
                </td>
                <td><input type="number" step="any" min="0" value="%5$s" name="dp_ap[%2$s][%3$s][attribute_price]"></td>
                <td><div class="dp-ap__delete-row">x</div></td></tr>',
                esc_attr($term->term_id),
                esc_attr($taxonomy_name),
                esc_attr($term_slug),
                esc_html($term->name),
                esc_attr($price)
            );
        }
        echo '</tbody></table>';

        echo '<div class="dp-ap__add-row-buttons">';
        echo '<button type="button" class="dp-ap__add-custom-row">' . esc_html__('Add custom attribute', 'dp-attribute-pricing') . '</button>';
        echo '<button type="button" class="dp-ap__add-global-row">' . esc_html__('Add global attribute', 'dp-attribute-pricing') . '</button>';
        echo '</div>';

        echo '</div>';
    }

    public function enqueue_assets()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'product') return;

        wp_enqueue_style(
            'dp-ap-admin',
            DP_AP_URL . 'assets/css/admin.css',
            [],
            DP_AP_VERSION
        );
        wp_enqueue_script(
            'dp-ap-admin',
            DP_AP_URL . 'assets/js/admin.js',
            ['jquery'],
            DP_AP_VERSION,
            true
        );
        wp_localize_script('dp-ap-admin', 'DPAttributePricing', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'getAttribute'       => wp_create_nonce('dp_ap_get_attribute'),
                'checkAttribute'     => wp_create_nonce('dp_ap_check_attribute'),
                'addTermToAttribute' => wp_create_nonce('dp_ap_add_term_to_attribute'),
            ],
            'i18n' => [
                'pleaseSelectFirst' => __('Please confirm or cancel the open input first.', 'dp-attribute-pricing'),
                'allUsed'           => __('All values for this attribute are already added.', 'dp-attribute-pricing'),
                'termNameRequired'  => __('Term name cannot be empty.', 'dp-attribute-pricing'),
                'errorAddingTerm'   => __('Error adding new term.', 'dp-attribute-pricing'),
                'value'             => __('Value', 'dp-attribute-pricing'),
                'additionalPrice'   => __('Additional price', 'dp-attribute-pricing'),
                'action'            => __('Action', 'dp-attribute-pricing'),
                'addCustom'         => __('Add custom attribute', 'dp-attribute-pricing'),
                'addGlobal'         => __('Add global attribute', 'dp-attribute-pricing'),
            ],
        ]);
    }
}
