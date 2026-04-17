<?php
/**
 * Plugin Name: DP Toolbox
 * Description: Design Pixels gereedschapskist — modulaire verzameling van site-tools.
 * Version: 2.7.2
 * Author: Design Pixels
 * Text Domain: dp-toolbox
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DP_TOOLBOX_VERSION', '2.7.2' );
define( 'DP_TOOLBOX_PATH', plugin_dir_path( __FILE__ ) );
define( 'DP_TOOLBOX_URL', plugin_dir_url( __FILE__ ) );

/**
 * Get metadata from a module's main file header.
 */
function dp_toolbox_get_module_info( $module_file ) {
    $headers = [
        'name'        => 'Module Name',
        'description' => 'Description',
        'version'     => 'Version',
    ];
    return get_file_data( $module_file, $headers );
}

/**
 * Discover all available modules (enabled or not).
 */
function dp_toolbox_get_available_modules() {
    $modules_dir = DP_TOOLBOX_PATH . 'modules/';
    $modules     = [];

    if ( ! is_dir( $modules_dir ) ) {
        return $modules;
    }

    foreach ( glob( $modules_dir . '*/' ) as $module_path ) {
        $slug        = basename( $module_path );
        $module_file = $module_path . $slug . '.php';

        if ( ! file_exists( $module_file ) ) {
            continue;
        }

        $info = dp_toolbox_get_module_info( $module_file );

        $modules[ $slug ] = [
            'slug'        => $slug,
            'file'        => $module_file,
            'name'        => $info['name'] ?: $slug,
            'description' => $info['description'] ?: '',
            'version'     => $info['version'] ?: '',
        ];
    }

    ksort( $modules );
    return $modules;
}

/**
 * Get list of enabled module slugs.
 */
function dp_toolbox_get_enabled_modules() {
    return (array) get_option( 'dp_toolbox_enabled_modules', [] );
}

/**
 * Check if a specific module is enabled.
 */
function dp_toolbox_is_module_enabled( $slug ) {
    return in_array( $slug, dp_toolbox_get_enabled_modules(), true );
}

/**
 * Enable Quick Setup by default on plugin activation.
 */
register_activation_hook( __FILE__, function () {
    $enabled = (array) get_option( 'dp_toolbox_enabled_modules', [] );
    if ( ! in_array( 'quick-setup', $enabled, true ) ) {
        $enabled[] = 'quick-setup';
        update_option( 'dp_toolbox_enabled_modules', array_values( $enabled ) );
    }
} );

/**
 * Migrate dashboard-welcome + dashboard-cleanup → dashboard-widgets (v1.2.0).
 */
function dp_toolbox_migrate_dashboard_widgets() {
    if ( get_option( 'dp_toolbox_dashboard_migrated' ) ) {
        return;
    }

    $enabled = (array) get_option( 'dp_toolbox_enabled_modules', [] );
    $old     = [ 'dashboard-welcome', 'dashboard-cleanup' ];
    $found   = array_intersect( $old, $enabled );

    if ( ! empty( $found ) ) {
        $enabled = array_diff( $enabled, $old );
        if ( ! in_array( 'dashboard-widgets', $enabled, true ) ) {
            $enabled[] = 'dashboard-widgets';
        }
        update_option( 'dp_toolbox_enabled_modules', array_values( $enabled ) );
    }

    update_option( 'dp_toolbox_dashboard_migrated', 1 );
}
add_action( 'plugins_loaded', 'dp_toolbox_migrate_dashboard_widgets', 5 );

/**
 * Load only enabled modules.
 */
function dp_toolbox_load_modules() {
    $enabled = dp_toolbox_get_enabled_modules();

    foreach ( $enabled as $slug ) {
        $module_file = DP_TOOLBOX_PATH . 'modules/' . $slug . '/' . $slug . '.php';
        if ( file_exists( $module_file ) ) {
            require_once $module_file;
        }
    }
}
add_action( 'plugins_loaded', 'dp_toolbox_load_modules' );

/**
 * Module conflict notices.
 * Returns an array of slug => notice string for modules that overlap
 * with functionality already provided by the active theme or plugins.
 */
function dp_toolbox_get_module_notices() {
    $notices = [];
    $theme   = wp_get_theme()->get_template();

    if ( $theme === 'bricks' ) {
        $notices['duplicate-post']    = 'Bricks heeft een ingebouwde dupliceerfunctie. Deze module is overbodig met het Bricks-thema.';
        $notices['maintenance-mode']  = 'Bricks heeft een ingebouwde onderhoudsmodus. Deze module is overbodig met het Bricks-thema.';
    }

    return apply_filters( 'dp_toolbox_module_notices', $notices );
}

/* ------------------------------------------------------------------ */
/*  Noindex indicator in admin bar                                     */
/* ------------------------------------------------------------------ */

add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    if ( '0' === get_option( 'blog_public' ) ) {
        $wp_admin_bar->add_node( [
            'id'    => 'dp-toolbox-noindex',
            'title' => '&#9888; NOINDEX',
            'href'  => admin_url( 'options-reading.php' ),
            'meta'  => [
                'class' => 'dp-toolbox-noindex-bar',
                'title' => 'Deze site wordt niet geïndexeerd door zoekmachines',
            ],
        ] );
    }
}, 999 );

add_action( 'admin_head', 'dp_toolbox_noindex_bar_css' );
add_action( 'wp_head', 'dp_toolbox_noindex_bar_css' );

function dp_toolbox_noindex_bar_css() {
    if ( '0' !== get_option( 'blog_public' ) ) return;
    echo '<style>#wpadminbar #wp-admin-bar-dp-toolbox-noindex > .ab-item { background: #d63638 !important; color: #fff !important; font-weight: 700 !important; letter-spacing: 0.5px; }</style>';
}

/* Shared admin UI + Settings page — always loaded */
require_once DP_TOOLBOX_PATH . 'includes/admin-ui.php';
require_once DP_TOOLBOX_PATH . 'includes/settings-page.php';