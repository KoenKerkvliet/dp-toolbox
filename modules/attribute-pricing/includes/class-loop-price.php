<?php
defined('ABSPATH') || exit;

/**
 * Prefix the displayed price with "Vanaf" on shop/category/related-product
 * listings when a product has at least one attribute option with a surcharge.
 *
 * Skipped on the single-product page because the live price calculator
 * there already shows the dynamic total.
 */
class DP_AP_Loop_Price
{
    public function __construct()
    {
        add_filter('woocommerce_get_price_html', [$this, 'prefix_from_label'], 10, 2);
    }

    public function prefix_from_label($price_html, $product)
    {
        if (!$product instanceof WC_Product) return $price_html;
        if (is_singular('product')) return $price_html;
        if (!$product->is_type('simple')) return $price_html;

        if (!$this->has_surcharge($product->get_id())) return $price_html;

        return sprintf(
            '<span class="dp-ap-price-from-label">%s </span>%s',
            esc_html_x('Vanaf', 'Price prefix on listings when product has paid options', 'dp-attribute-pricing'),
            $price_html
        );
    }

    /**
     * True when at least one option for the product has attribute_price > 0.
     */
    private function has_surcharge($product_id)
    {
        $meta = get_post_meta($product_id, DP_AP_META_KEY, true);
        if (!is_array($meta) || empty($meta)) return false;

        foreach ($meta as $rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                if (isset($row['attribute_price']) && (float) $row['attribute_price'] > 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
