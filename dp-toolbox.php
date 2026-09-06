<?php
/**
 * Plugin Name: DP Toolbox
 * Description: Design Pixels gereedschapskist — modulaire verzameling van site-tools.
 * Version: 2.55.0
 * Author: Design Pixels
 * Text Domain: dp-toolbox
 * GitHub Plugin URI: KoenKerkvliet/dp-toolbox
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DP_TOOLBOX_VERSION', '2.55.0' );
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
        'requires'    => 'Requires',
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
            'requires'    => $info['requires'] ?: '',
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
 * De oplevercheck was een vast tabblad en is sinds 2.45.0 een module.
 *
 * Standaard staat een module uit. Op sites waar de lijst al gebruikt werd — er
 * staan afgevinkte punten in — zetten we hem eenmalig aan, zodat een oplevering
 * die halverwege is niet ineens uit beeld verdwijnt.
 */
function dp_toolbox_migrate_checklist_module() {
    if ( get_option( 'dp_toolbox_checklist_migrated' ) ) {
        return;
    }

    $state = get_option( 'dp_toolbox_checklist_state', [] );

    if ( is_array( $state ) && ! empty( $state ) ) {
        $enabled = (array) get_option( 'dp_toolbox_enabled_modules', [] );
        if ( ! in_array( 'checklist', $enabled, true ) ) {
            $enabled[] = 'checklist';
            update_option( 'dp_toolbox_enabled_modules', array_values( $enabled ) );
        }
    }

    update_option( 'dp_toolbox_checklist_migrated', 1, false );
}
add_action( 'plugins_loaded', 'dp_toolbox_migrate_checklist_module', 5 );

/**
 * Een inlogpagina mag nooit uit een paginacache komen.
 *
 * WordPress zet zelf al no-cache-headers op wp-login.php, maar cacheplugins
 * gaan af op hun eigen uitsluitingslijst — en die noemt letterlijk
 * `wp-login.php`. Verplaats je je login (met AIOS, of met onze eigen Custom
 * Login URL), dan krijg je een adres dat eruitziet als een gewone pagina, en
 * dat wordt dus gewoon gecachet.
 *
 * Gevolgen die je niet meteen als cacheprobleem herkent: aanpassingen aan de
 * loginpagina lijken niets te doen, en in het ergste geval krijgt de volgende
 * bezoeker een meldingsregel te zien die voor iemand anders bedoeld was.
 *
 * `login_init` draait in wp-login.php vóór er iets is uitgestuurd, ook wanneer
 * een plugin die file onder een eigen adres inlaadt.
 */
function dp_toolbox_nocache_login_page() {
    /**
     * Uit te zetten per site, mocht een cache-opstelling hier anders mee omgaan.
     */
    if ( ! apply_filters( 'dp_toolbox_nocache_login', true ) ) {
        return;
    }

    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    do_action( 'litespeed_control_set_nocache', 'DP Toolbox: inlogpagina' );
}
add_action( 'login_init', 'dp_toolbox_nocache_login_page', 0 );

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
 * Draait deze site op Bricks?
 *
 * Bricks is een thema, dus we kijken naar `template` — dat dekt ook een
 * child-thema als `bricks-child`. Constanten als `BRICKS_VERSION` zijn hier nog
 * niet beschikbaar: dit draait op plugins_loaded, vóór het thema geladen is.
 */
function dp_toolbox_bricks_is_available() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    return $cached = in_array( 'bricks', [ get_option( 'template' ), get_option( 'stylesheet' ) ], true );
}

/**
 * Is All-In-One Security actief op deze site?
 */
function dp_toolbox_aios_is_available() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    $bestand = 'all-in-one-wp-security-and-firewall/wp-security.php';

    // Optie i.p.v. is_plugin_active(): dit draait al op plugins_loaded.
    if ( in_array( $bestand, (array) get_option( 'active_plugins', [] ), true ) ) {
        return $cached = true;
    }

    if ( is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', [] ) )[ $bestand ] ) ) {
        return $cached = true;
    }

    return $cached = false;
}

/**
 * Draait deze site op WooCommerce?
 *
 * Optie i.p.v. class_exists('WooCommerce'): dit draait op plugins_loaded,
 * waar de klasse van een andere plugin er nog niet hoeft te zijn.
 */
function dp_toolbox_woocommerce_is_available() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    return $cached = dp_toolbox_plugin_is_active( 'woocommerce/woocommerce.php' );
}

/**
 * Draait deze site op FluentCart?
 */
function dp_toolbox_fluentcart_is_available() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    return $cached = dp_toolbox_plugin_is_active( 'fluent-cart/fluent-cart.php' );
}

/**
 * Is een plugin actief, netwerkbreed of op deze site?
 */
function dp_toolbox_plugin_is_active( $bestand ) {
    if ( in_array( $bestand, (array) get_option( 'active_plugins', [] ), true ) ) {
        return true;
    }

    if ( is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', [] ) )[ $bestand ] ) ) {
        return true;
    }

    return false;
}

/**
 * Onvervulde vereisten per module: slug => [ 'met' => bool, 'reason' => string ].
 *
 * Een module verklaart zelf waar hij van afhangt, met de header `Requires:`.
 * Zo staat de eis bij de module in plaats van in een lijst hier die je vergeet
 * bij te werken zodra er een module bijkomt. Modules zonder die header hebben
 * geen vereisten.
 *
 * @param string|null $alleen_slug Beperk de scan tot deze ene module. De
 *                                 modulepagina vraagt de hele lijst; het laden
 *                                 van modules hoeft alleen naar zichzelf te
 *                                 kijken en leest dan niet de headers van
 *                                 veertig bestanden per paginaweergave.
 */
function dp_toolbox_get_module_requirements( $alleen_slug = null ) {
    $reqs = [];

    if ( null !== $alleen_slug ) {
        $bestand = DP_TOOLBOX_PATH . 'modules/' . $alleen_slug . '/' . $alleen_slug . '.php';
        $modules = file_exists( $bestand )
            ? [ $alleen_slug => dp_toolbox_get_module_info( $bestand ) ]
            : [];
    } else {
        $modules = dp_toolbox_get_available_modules();
    }

    foreach ( $modules as $slug => $module ) {
        $vereist = strtolower( trim( $module['requires'] ?? '' ) );
        if ( '' === $vereist ) {
            continue;
        }

        $oordeel = dp_toolbox_check_requirement( $vereist );

        // Onbekende vereiste blokkeert niets: liever een module die werkt dan
        // een module die onbereikbaar wordt door een typefout in een header.
        if ( null === $oordeel || ! empty( $oordeel['met'] ) ) {
            continue;
        }

        $reqs[ $slug ] = $oordeel;
    }

    return apply_filters( 'dp_toolbox_module_requirements', $reqs );
}

/**
 * Vertaalt één `Requires:`-waarde naar beschikbaarheid plus uitleg.
 *
 * De uitleg noemt waar mogelijk wat er dan wél draait. "Deze webshop draait op
 * FluentCart" vertelt meer dan "WooCommerce niet gevonden", en voorkomt de vraag
 * of er iets stuk is.
 */
function dp_toolbox_check_requirement( $vereist ) {
    switch ( $vereist ) {
        case 'bricks':
            return [
                'met'    => dp_toolbox_bricks_is_available(),
                'reason' => dp_toolbox_etch_is_available()
                    ? 'Vereist Bricks. Deze site draait op Etch — de snelnavigatie wijst naar schermen die hier niet bestaan.'
                    : 'Vereist het Bricks-thema. Niet gevonden op deze site.',
            ];

        case 'etch':
            return [
                'met'    => dp_toolbox_etch_is_available(),
                'reason' => get_option( 'template' ) === 'bricks'
                    ? 'Vereist Etch. Deze site draait op Bricks — gebruik daar Bricksforge voor animaties.'
                    : 'Vereist het Etch-thema of de Etch-plugin. Niet gevonden op deze site.',
            ];

        case 'aios':
            return [
                'met'    => dp_toolbox_aios_is_available(),
                'reason' => 'Vereist All-In-One Security (AIOS). Die plugin verstuurt de uitsluitingsmails die deze module filtert.',
            ];

        case 'woocommerce':
            return [
                'met'    => dp_toolbox_woocommerce_is_available(),
                'reason' => dp_toolbox_fluentcart_is_available()
                    ? 'Vereist WooCommerce. Deze webshop draait op FluentCart.'
                    : 'Vereist WooCommerce. Niet gevonden op deze site.',
            ];

        case 'fluent-cart':
            return [
                'met'    => dp_toolbox_fluentcart_is_available(),
                'reason' => dp_toolbox_woocommerce_is_available()
                    ? 'Vereist FluentCart. Deze webshop draait op WooCommerce.'
                    : 'Vereist FluentCart. Niet gevonden op deze site.',
            ];
    }

    return null;
}

/**
 * Mag deze module aangezet en geladen worden?
 */
function dp_toolbox_module_requirement_met( $slug ) {
    $reqs = dp_toolbox_get_module_requirements( $slug );
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
require_once DP_TOOLBOX_PATH . 'includes/settings-page.php';
require_once DP_TOOLBOX_PATH . 'includes/import-export.php';