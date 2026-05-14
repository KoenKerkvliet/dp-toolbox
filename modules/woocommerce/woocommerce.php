<?php
/**
 * Module Name: WooCommerce
 * Description: Verzameling van WooCommerce-features. Per feature aan/uit te zetten op de module-pagina (Attribute Pricing, etc.).
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DP_TOOLBOX_WC_DIR', __DIR__ );
define( 'DP_TOOLBOX_WC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Discover all available features in features/<slug>/<slug>.php.
 * Returns an associative array keyed by feature slug:
 *   [
 *     'attribute-pricing' => [
 *       'slug'        => 'attribute-pricing',
 *       'file'        => '/.../features/attribute-pricing/attribute-pricing.php',
 *       'name'        => 'Attribute Pricing',
 *       'description' => '...',
 *       'version'     => '1.0.0',
 *     ],
 *   ]
 */
function dp_toolbox_wc_get_available_features() {
    $features_dir = DP_TOOLBOX_WC_DIR . '/features/';
    $features     = [];

    if ( ! is_dir( $features_dir ) ) {
        return $features;
    }

    foreach ( glob( $features_dir . '*/' ) as $feature_path ) {
        $slug = basename( $feature_path );
        $file = $feature_path . $slug . '.php';

        if ( ! file_exists( $file ) ) {
            continue;
        }

        $info = get_file_data( $file, [
            'name'        => 'Feature Name',
            'description' => 'Description',
            'version'     => 'Version',
        ] );

        $features[ $slug ] = [
            'slug'        => $slug,
            'file'        => $file,
            'name'        => $info['name'] ?: $slug,
            'description' => $info['description'] ?: '',
            'version'     => $info['version'] ?: '',
        ];
    }

    ksort( $features );
    return $features;
}

/**
 * Return enabled feature slugs.
 */
function dp_toolbox_wc_get_enabled_features() {
    return (array) get_option( 'dp_toolbox_wc_features', [] );
}

/**
 * Check if a feature is enabled.
 */
function dp_toolbox_wc_is_feature_enabled( $slug ) {
    return in_array( $slug, dp_toolbox_wc_get_enabled_features(), true );
}

/**
 * Load enabled features. Each feature's main file bootstraps itself on
 * `plugins_loaded` (or later) and is expected to no-op if WC is missing.
 */
function dp_toolbox_wc_load_features() {
    $enabled  = dp_toolbox_wc_get_enabled_features();
    $features = dp_toolbox_wc_get_available_features();

    foreach ( $enabled as $slug ) {
        if ( isset( $features[ $slug ] ) ) {
            require_once $features[ $slug ]['file'];
        }
    }
}
dp_toolbox_wc_load_features();

/**
 * Admin notice when WooCommerce isn't active but features are enabled.
 */
add_action( 'admin_notices', function () {
    if ( class_exists( 'WooCommerce' ) ) return;
    if ( empty( dp_toolbox_wc_get_enabled_features() ) ) return;

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_dp-toolbox' ) return;

    echo '<div class="notice notice-warning"><p><strong>DP Toolbox — WooCommerce-module:</strong> één of meerdere features staan aan, maar WooCommerce is niet actief. De features doen pas iets zodra je WooCommerce activeert.</p></div>';
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
