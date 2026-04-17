<?php
/**
 * Module Name: Custom Login URL
 * Description: Verplaats de WordPress login-pagina naar een eigen URL en blokkeer wp-login.php.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Get the custom login slug                                          */
/* ------------------------------------------------------------------ */

function dp_toolbox_clu_get_slug() {
    return sanitize_title( get_option( 'dp_toolbox_login_slug', '' ) );
}

/* ------------------------------------------------------------------ */
/*  Register the custom URL as a rewrite rule                          */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
    $slug = dp_toolbox_clu_get_slug();
    if ( empty( $slug ) ) {
        return;
    }

    add_rewrite_rule(
        '^' . preg_quote( $slug, '/' ) . '/?$',
        'index.php?dp_custom_login=1',
        'top'
    );
} );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'dp_custom_login';
    return $vars;
} );

/* ------------------------------------------------------------------ */
/*  Handle the custom login URL request                                */
/* ------------------------------------------------------------------ */

add_action( 'template_redirect', function () {
    if ( get_query_var( 'dp_custom_login' ) !== '1' ) {
        return;
    }

    // Already logged in? Go to admin
    if ( is_user_logged_in() ) {
        wp_redirect( admin_url() );
        exit;
    }

    // Load wp-login.php
    require_once ABSPATH . 'wp-login.php';
    exit;
} );

/* ------------------------------------------------------------------ */
/*  Block direct access to wp-login.php                                */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
    $slug = dp_toolbox_clu_get_slug();
    if ( empty( $slug ) ) {
        return;
    }

    // Don't block if already logged in
    if ( is_user_logged_in() ) {
        return;
    }

    // Check if this is a wp-login.php request
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( strpos( $request_uri, 'wp-login.php' ) === false ) {
        return;
    }

    // Allow POST requests for login processing (form submits to wp-login.php)
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        return;
    }

    // Allow specific actions that need wp-login.php (password reset, logout confirmation)
    $allowed_actions = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'postpass', 'confirm_admin_email' ];
    $action = $_GET['action'] ?? '';
    if ( in_array( $action, $allowed_actions, true ) ) {
        return;
    }

    // Allow emergency bypass: ?dp_emergency=AUTH_KEY_first_8_chars
    $emergency = $_GET['dp_emergency'] ?? '';
    if ( ! empty( $emergency ) ) {
        $key = substr( md5( AUTH_KEY ), 0, 8 );
        if ( $emergency === $key ) {
            return;
        }
    }

    // Block: redirect to 404
    wp_redirect( home_url( '/404' ), 302 );
    exit;
}, 1 ); // Priority 1: run before other plugins

/* ------------------------------------------------------------------ */
/*  Fix login URL in WordPress functions                               */
/* ------------------------------------------------------------------ */

add_filter( 'login_url', function ( $login_url, $redirect, $force_reauth ) {
    $slug = dp_toolbox_clu_get_slug();
    if ( empty( $slug ) ) {
        return $login_url;
    }

    $new_url = home_url( '/' . $slug . '/' );

    if ( ! empty( $redirect ) ) {
        $new_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $new_url );
    }

    if ( $force_reauth ) {
        $new_url = add_query_arg( 'reauth', '1', $new_url );
    }

    return $new_url;
}, 10, 3 );

// Fix logout redirect
add_filter( 'logout_redirect', function ( $redirect_to ) {
    $slug = dp_toolbox_clu_get_slug();
    if ( ! empty( $slug ) ) {
        return home_url( '/' . $slug . '/?loggedout=true' );
    }
    return $redirect_to;
} );

// Fix lostpassword URL
add_filter( 'lostpassword_url', function ( $url, $redirect ) {
    $slug = dp_toolbox_clu_get_slug();
    if ( empty( $slug ) ) {
        return $url;
    }
    $new_url = site_url( 'wp-login.php?action=lostpassword' );
    if ( ! empty( $redirect ) ) {
        $new_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $new_url );
    }
    return $new_url;
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  Flush rewrite rules when slug changes                              */
/* ------------------------------------------------------------------ */

add_action( 'update_option_dp_toolbox_login_slug', function () {
    flush_rewrite_rules();
} );

// Also flush on module activation
add_action( 'update_option_dp_toolbox_enabled_modules', function ( $old, $new ) {
    $was_active = is_array( $old ) && in_array( 'custom-login-url', $old, true );
    $is_active  = is_array( $new ) && in_array( 'custom-login-url', $new, true );

    if ( $was_active !== $is_active ) {
        flush_rewrite_rules();
    }
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  Conflict detection                                                 */
/* ------------------------------------------------------------------ */

add_filter( 'dp_toolbox_module_notices', function ( $notices ) {
    // Check AIOS custom login
    $aios_options = get_option( 'aio_wp_security_configs', [] );
    if ( ! empty( $aios_options['aiowps_enable_rename_login_page'] ) ) {
        $notices['custom-login-url'] = 'AIOS heeft ook een custom login URL actief. Gebruik slechts één van beide.';
    }
    return $notices;
} );

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
