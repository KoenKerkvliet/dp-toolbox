<?php
/**
 * Module Name: Maintenance Mode
 * Description: Toon een onderhoudspagina aan bezoekers terwijl je aan de site werkt.
 * Category: security
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Leeg de paginacache van elke cacheplugin die we kennen.
 *
 * Zonder dit doet de onderhoudsmodus ogenschijnlijk niets: een cacheplugin
 * serveert de HTML die vóór het inschakelen is opgeslagen, en dan draait PHP
 * niet eens — de hook hieronder komt dus nooit aan de beurt.
 */
function dp_toolbox_mm_purge_caches() {
    // LiteSpeed Cache
    do_action( 'litespeed_purge_all' );

    // Cache Enabler
    do_action( 'cache_enabler_clear_complete_cache' );

    // WP Rocket
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
    }

    // W3 Total Cache
    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
    }

    // WP Super Cache
    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        wp_cache_clear_cache();
    }

    // SiteGround Optimizer
    if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
        sg_cachepress_purge_cache();
    }

    /**
     * Voor hosting- of CDN-caches die hier niet in staan.
     */
    do_action( 'dp_toolbox_mm_purge_caches' );
}

/* Cache legen zodra de schakelaar omgaat — in beide richtingen. */
add_action( 'update_option_dp_toolbox_maintenance_enabled', 'dp_toolbox_mm_purge_caches', 10, 0 );
add_action( 'add_option_dp_toolbox_maintenance_enabled', 'dp_toolbox_mm_purge_caches', 10, 0 );

/*
 * Opslaan zonder de schakelaar te verzetten slaat update_option() over, en
 * daarmee ook de purge hierboven. Juist dán wil je kunnen legen: als de cache
 * is blijven hangen, is nogmaals opslaan de eerste reflex.
 */
add_filter( 'pre_update_option_dp_toolbox_maintenance_enabled', function ( $value, $old_value ) {
    if ( maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
        dp_toolbox_mm_purge_caches();
    }
    return $value;
}, 10, 2 );

add_action( 'template_redirect', function () {
    if ( ! get_option( 'dp_toolbox_maintenance_enabled', false ) ) {
        return;
    }
    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
        return;
    }
    if ( is_admin() || ( $GLOBALS['pagenow'] ?? '' ) === 'wp-login.php' ) {
        return;
    }
    if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }

    /*
     * Deze pagina mag nooit de cache in. Anders blijft de onderhoudspagina
     * hangen nadat je de modus weer uitzet.
     */
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    do_action( 'litespeed_control_set_nocache', 'DP Toolbox onderhoudsmodus' );

    $site_name = get_bloginfo( 'name' );
    $logo_url  = dp_toolbox_branding_logo_url();
    $credit    = dp_toolbox_branding_credit_html();
    $gradient  = dp_toolbox_branding_color( 'gradient' );

    nocache_headers();
    header( 'HTTP/1.1 503 Service Temporarily Unavailable' );
    header( 'Retry-After: 3600' );

    /*
     * Een 503 is precies goed voor zoekmachines — tijdelijk weg, kom terug —
     * maar een uptime-monitor leest 'm terecht als "site offline". Deze header
     * laat een monitor het verschil zien tussen onderhoud en een storing, zodat
     * een geplande onderhoudsbeurt niet als downtime in een klantrapport belandt.
     */
    header( 'X-DP-Maintenance: 1' );
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html( $site_name ); ?> - Onderhoud</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: <?php echo esc_attr( $gradient ); ?>;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #fff; padding: 24px;
        }
        .box { text-align: center; max-width: 520px; }
        .logo { height: 50px; max-width: 240px; object-fit: contain; margin-bottom: 32px; opacity: 0.9; }
        .sitename { font-size: 15px; font-weight: 600; letter-spacing: 0.04em;
            text-transform: uppercase; opacity: 0.65; margin-bottom: 28px; }
        .icon { font-size: 48px; margin-bottom: 20px; }
        h1 { font-size: 28px; font-weight: 700; margin-bottom: 12px; }
        p { font-size: 16px; line-height: 1.6; opacity: 0.8; }
        .foot { margin-top: 48px; font-size: 12px; opacity: 0.4; }
        .foot a { color: #c4b5fd; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" class="logo">
        <?php else : ?>
            <div class="sitename"><?php echo esc_html( $site_name ); ?></div>
        <?php endif; ?>
        <div class="icon">&#128295;</div>
        <h1>Even geduld...</h1>
        <p>We werken aan verbeteringen. De site is binnenkort weer beschikbaar.</p>
        <?php if ( $credit ) : ?>
            <div class="foot"><?php echo $credit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
    exit;
} );

add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    if ( ! get_option( 'dp_toolbox_maintenance_enabled', false ) ) return;
    $wp_admin_bar->add_node( [
        'id'    => 'dp-maintenance-notice',
        'title' => '&#128295; Onderhoudsmodus actief',
        'href'  => admin_url( 'admin.php?page=dp-toolbox#settings-maintenance-mode' ),
        'meta'  => [ 'class' => 'dp-maintenance-bar-notice' ],
    ] );
}, 999 );

add_action( 'admin_head', function () {
    if ( ! get_option( 'dp_toolbox_maintenance_enabled', false ) ) return;
    echo '<style>#wpadminbar #wp-admin-bar-dp-maintenance-notice > .ab-item { background: #d63638 !important; color: #fff !important; font-weight: 600 !important; }</style>';
} );

add_action( 'wp_head', function () {
    if ( ! get_option( 'dp_toolbox_maintenance_enabled', false ) ) return;
    echo '<style>#wpadminbar #wp-admin-bar-dp-maintenance-notice > .ab-item { background: #d63638 !important; color: #fff !important; font-weight: 600 !important; }</style>';
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
