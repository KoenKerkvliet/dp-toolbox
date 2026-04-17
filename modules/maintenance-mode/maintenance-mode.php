<?php
/**
 * Module Name: Maintenance Mode
 * Description: Toon een onderhoudspagina aan bezoekers terwijl je aan de site werkt.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'template_redirect', function () {
    if ( ! get_option( 'dp_toolbox_maintenance_enabled', false ) ) {
        return;
    }
    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
        return;
    }
    if ( is_admin() || $GLOBALS['pagenow'] === 'wp-login.php' ) {
        return;
    }
    if ( defined( 'DOING_AJAX' ) || defined( 'REST_REQUEST' ) ) {
        return;
    }

    $site_name = get_bloginfo( 'name' );
    $logo_url  = DP_TOOLBOX_URL . 'assets/dp-logo.webp';

    header( 'HTTP/1.1 503 Service Temporarily Unavailable' );
    header( 'Retry-After: 3600' );
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
            background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #fff; padding: 24px;
        }
        .box { text-align: center; max-width: 520px; }
        .logo { height: 50px; margin-bottom: 32px; opacity: 0.9; }
        .icon { font-size: 48px; margin-bottom: 20px; }
        h1 { font-size: 28px; font-weight: 700; margin-bottom: 12px; }
        p { font-size: 16px; line-height: 1.6; opacity: 0.8; }
        .foot { margin-top: 48px; font-size: 12px; opacity: 0.4; }
        .foot a { color: #c4b5fd; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="" class="logo">
        <div class="icon">&#128295;</div>
        <h1>Even geduld...</h1>
        <p>We werken aan verbeteringen. De site is binnenkort weer beschikbaar.</p>
        <div class="foot"><a href="https://designpixels.nl" target="_blank" rel="noopener">Design Pixels</a></div>
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
        'href'  => admin_url( 'admin.php?page=dp-toolbox-maintenance' ),
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
