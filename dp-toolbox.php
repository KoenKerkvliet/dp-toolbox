<?php
/**
 * Plugin Name: DP Toolbox
 * Description: Design Pixels gereedschapskist — modulaire verzameling van site-tools.
 * Version: 2.44.0
 * Author: Design Pixels
 * Text Domain: dp-toolbox
 * GitHub Plugin URI: KoenKerkvliet/dp-toolbox
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DP_TOOLBOX_VERSION', '2.44.0' );
define( 'DP_TOOLBOX_PATH', plugin_dir_path( __FILE__ ) );
define( 'DP_TOOLBOX_URL', plugin_dir_url( __FILE__ ) );

/* ------------------------------------------------------------------ */
/*  DP-user check (plugin-wide)                                        */
/*  Users met @designpixels.nl e-mail zien de plugin in admin.         */
/*  Andere admins zien noch de plugin-regel in wp-admin/plugins.php    */
/*  noch het DP Toolbox menu — modules blijven wel actief functioneren.*/
/* ------------------------------------------------------------------ */
function dp_toolbox_is_dp_user( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return apply_filters( 'dp_toolbox_is_dp_user', false, $user_id );
    }

    $user  = get_userdata( $user_id );
    $is_dp = $user && ! empty( $user->user_email )
        && str_ends_with( strtolower( trim( $user->user_email ) ), '@designpixels.nl' );

    return apply_filters( 'dp_toolbox_is_dp_user', $is_dp, $user_id );
}

/**
 * Verberg de DP Toolbox plugin-regel in wp-admin/plugins.php voor niet-DP-users.
 * Priority 5 — vóór User Manager's per-user filter (110).
 */
add_filter( 'all_plugins', function ( $plugins ) {
    if ( dp_toolbox_is_dp_user() ) {
        return $plugins;
    }
    unset( $plugins['dp-toolbox/dp-toolbox.php'] );
    return $plugins;
}, 5 );

/**
 * Get metadata from a module's main file header.
 */
function dp_toolbox_get_module_info( $module_file ) {
    $headers = [
        'name'        => 'Module Name',
        'description' => 'Description',
        'version'     => 'Version',
        'category'    => 'Category',
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
            'category'    => $info['category'] ?: '',
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

/* ------------------------------------------------------------------ */
/*  Module-vereisten                                                    */
/*  Sommige modules slaan alleen ergens op met een specifieke builder.  */
/*  Een module met een onvervulde vereiste kan niet aangezet worden     */
/*  (afgedwongen in de sanitize-callback van de instellingenpagina) en  */
/*  wordt sowieso niet geladen.                                         */
/* ------------------------------------------------------------------ */

/**
 * Is Etch beschikbaar op deze site — als thema of als plugin?
 */
function dp_toolbox_etch_is_available() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    // Opties i.p.v. wp_get_theme(): dit draait al op plugins_loaded.
    if ( in_array( 'etch-theme', [ get_option( 'template' ), get_option( 'stylesheet' ) ], true ) ) {
        return $cached = true;
    }

    if ( class_exists( '\Etch\WpApi' ) ) {
        return $cached = true;
    }

    if ( file_exists( WP_PLUGIN_DIR . '/etch/etch.php' ) ) {
        if ( in_array( 'etch/etch.php', (array) get_option( 'active_plugins', [] ), true ) ) {
            return $cached = true;
        }
        if ( is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', [] ) )['etch/etch.php'] ) ) {
            return $cached = true;
        }
    }

    return $cached = false;
}

/**
 * Onvervulde vereisten per module: slug => [ 'met' => bool, 'reason' => string ].
 * Modules zonder entry hebben geen vereisten.
 */
function dp_toolbox_get_module_requirements() {
    $reqs = [];

    if ( ! dp_toolbox_etch_is_available() ) {
        $reqs['etch-gsap'] = [
            'met'    => false,
            'reason' => get_option( 'template' ) === 'bricks'
                ? 'Vereist Etch. Deze site draait op Bricks — gebruik daar Bricksforge voor animaties.'
                : 'Vereist het Etch-thema of de Etch-plugin. Niet gevonden op deze site.',
        ];
    }

    return apply_filters( 'dp_toolbox_module_requirements', $reqs );
}

/**
 * Mag deze module aangezet worden op deze site?
 */
function dp_toolbox_module_requirement_met( $slug ) {
    $reqs = dp_toolbox_get_module_requirements();
    return ! isset( $reqs[ $slug ] ) || ! empty( $reqs[ $slug ]['met'] );
}

/**
 * Load only enabled modules.
 */
function dp_toolbox_load_modules() {
    $enabled = dp_toolbox_get_enabled_modules();

    foreach ( $enabled as $slug ) {
        // Vereiste weggevallen (bv. site omgebouwd van Etch naar Bricks):
        // niets laden, ook al staat de module nog aan in de database.
        if ( ! dp_toolbox_module_requirement_met( $slug ) ) {
            continue;
        }

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
/*  Noindex indicator in admin bar — alleen voor DP-users              */
/* ------------------------------------------------------------------ */

add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    if ( ! dp_toolbox_is_dp_user() ) return;
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
    if ( ! dp_toolbox_is_dp_user() ) return;
    if ( '0' !== get_option( 'blog_public' ) ) return;
    echo '<style>#wpadminbar #wp-admin-bar-dp-toolbox-noindex > .ab-item { background: #d63638 !important; color: #fff !important; font-weight: 700 !important; letter-spacing: 0.5px; }</style>';
}

/* ------------------------------------------------------------------ */
/*  Verberg Novamira-plugin admin-bar indicator voor niet-DP-users.    */
/*  De Novamira-plugin (3rd party MCP) toont een rode "Novamira ON"    */
/*  badge — alleen relevant voor DP. Late hook + remove_node, zodat    */
/*  we de upstream plugin niet hoeven te patchen.                      */
/* ------------------------------------------------------------------ */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    if ( dp_toolbox_is_dp_user() ) return;
    $wp_admin_bar->remove_node( 'novamira-mcp-status' );
}, PHP_INT_MAX );

/* Shared admin UI + Settings page — always loaded */
require_once DP_TOOLBOX_PATH . 'includes/branding.php';
require_once DP_TOOLBOX_PATH . 'includes/admin-ui.php';
require_once DP_TOOLBOX_PATH . 'includes/checklist.php';
require_once DP_TOOLBOX_PATH . 'includes/settings-page.php';
require_once DP_TOOLBOX_PATH . 'includes/import-export.php';