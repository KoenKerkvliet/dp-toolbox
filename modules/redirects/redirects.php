<?php
/**
 * Module Name: Redirects
 * Description: Beheer 301/302 redirects vanuit WordPress — zonder extra plugin.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Get all redirects                                                  */
/* ------------------------------------------------------------------ */

function dp_toolbox_redirects_get_all() {
    return (array) get_option( 'dp_toolbox_redirects', [] );
}

/* ------------------------------------------------------------------ */
/*  Handle redirects on every frontend request                         */
/* ------------------------------------------------------------------ */

add_action( 'template_redirect', function () {
    if ( is_admin() ) {
        return;
    }

    $redirects = dp_toolbox_redirects_get_all();
    if ( empty( $redirects ) ) {
        return;
    }

    $request_path = rtrim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    if ( empty( $request_path ) ) {
        $request_path = '/';
    }

    foreach ( $redirects as $id => &$rule ) {
        if ( empty( $rule['from'] ) || empty( $rule['to'] ) || empty( $rule['active'] ) ) {
            continue;
        }

        $from = rtrim( $rule['from'], '/' );
        $matched = false;

        // Regex match
        if ( ! empty( $rule['regex'] ) ) {
            if ( @preg_match( '#^' . $from . '$#i', $request_path ) ) {
                $target = preg_replace( '#^' . $from . '$#i', $rule['to'], $request_path );
                $matched = true;
            }
        } else {
            // Exact match (case-insensitive)
            if ( strcasecmp( $from, $request_path ) === 0 ) {
                $target = $rule['to'];
                $matched = true;
            }
        }

        if ( $matched ) {
            // Update hit counter
            $rule['hits'] = ( $rule['hits'] ?? 0 ) + 1;
            $rule['last_hit'] = current_time( 'mysql' );
            update_option( 'dp_toolbox_redirects', $redirects, false );

            $code = (int) ( $rule['type'] ?? 301 );
            wp_redirect( $target, $code );
            exit;
        }
    }
}, 1 ); // Priority 1: before other plugins

/* ------------------------------------------------------------------ */
/*  AJAX: add redirect                                                 */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_redirect_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_redirects', 'nonce' );

    $redirects = dp_toolbox_redirects_get_all();

    $id   = sanitize_text_field( $_POST['id'] ?? '' );
    $from = sanitize_text_field( $_POST['from'] ?? '' );
    $to   = esc_url_raw( $_POST['to'] ?? '' );
    $type = in_array( (int) ( $_POST['type'] ?? 301 ), [ 301, 302 ], true ) ? (int) $_POST['type'] : 301;
    $regex  = ! empty( $_POST['regex'] );
    $active = ! empty( $_POST['active'] );

    if ( empty( $from ) || empty( $to ) ) {
        wp_send_json_error( 'Van- en naar-URL zijn verplicht.' );
    }

    // Ensure "from" starts with /
    if ( ! $regex && strpos( $from, '/' ) !== 0 ) {
        $from = '/' . $from;
    }

    // Validate regex
    if ( $regex && @preg_match( '#^' . $from . '$#i', '' ) === false ) {
        wp_send_json_error( 'Ongeldige reguliere expressie.' );
    }

    // Check for duplicate "from" (exclude current id when editing)
    foreach ( $redirects as $existing_id => $rule ) {
        if ( $existing_id !== $id && $rule['from'] === $from ) {
            wp_send_json_error( 'Er bestaat al een redirect voor deze URL.' );
        }
    }

    $entry = [
        'from'     => $from,
        'to'       => $to,
        'type'     => $type,
        'regex'    => $regex,
        'active'   => $active,
        'hits'     => 0,
        'last_hit' => '',
        'created'  => current_time( 'mysql' ),
    ];

    // Editing existing
    if ( ! empty( $id ) && isset( $redirects[ $id ] ) ) {
        $entry['hits']     = $redirects[ $id ]['hits'] ?? 0;
        $entry['last_hit'] = $redirects[ $id ]['last_hit'] ?? '';
        $entry['created']  = $redirects[ $id ]['created'] ?? current_time( 'mysql' );
        $redirects[ $id ]  = $entry;
    } else {
        // New entry
        $new_id = 'r_' . uniqid();
        $redirects[ $new_id ] = $entry;
    }

    update_option( 'dp_toolbox_redirects', $redirects, false );
    wp_send_json_success( [ 'message' => 'Redirect opgeslagen.' ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: delete redirect                                              */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_redirect_delete', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_redirects', 'nonce' );

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    $redirects = dp_toolbox_redirects_get_all();

    if ( ! isset( $redirects[ $id ] ) ) {
        wp_send_json_error( 'Redirect niet gevonden.' );
    }

    unset( $redirects[ $id ] );
    update_option( 'dp_toolbox_redirects', $redirects, false );
    wp_send_json_success( [ 'message' => 'Redirect verwijderd.' ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: toggle active state                                          */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_redirect_toggle', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_redirects', 'nonce' );

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    $redirects = dp_toolbox_redirects_get_all();

    if ( ! isset( $redirects[ $id ] ) ) {
        wp_send_json_error( 'Redirect niet gevonden.' );
    }

    $redirects[ $id ]['active'] = ! $redirects[ $id ]['active'];
    update_option( 'dp_toolbox_redirects', $redirects, false );
    wp_send_json_success( [ 'active' => $redirects[ $id ]['active'] ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: reset hit counter                                            */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_redirect_reset_hits', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_redirects', 'nonce' );

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    $redirects = dp_toolbox_redirects_get_all();

    if ( ! isset( $redirects[ $id ] ) ) {
        wp_send_json_error( 'Redirect niet gevonden.' );
    }

    $redirects[ $id ]['hits'] = 0;
    $redirects[ $id ]['last_hit'] = '';
    update_option( 'dp_toolbox_redirects', $redirects, false );
    wp_send_json_success();
} );

/* ------------------------------------------------------------------ */
/*  Conflict detection                                                 */
/* ------------------------------------------------------------------ */

add_filter( 'dp_toolbox_module_notices', function ( $notices ) {
    $redirect_plugins = [
        '301-redirects/flavor-flavor.php',
        'redirection/redirection.php',
        'safe-redirect-manager/safe-redirect-manager.php',
        'eps-301-redirects/eps-301-redirects.php',
    ];
    foreach ( $redirect_plugins as $plugin ) {
        if ( is_plugin_active( $plugin ) ) {
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
            $notices['redirects'] = 'Mogelijk conflict met ' . $data['Name'] . '. Gebruik slechts één redirect-plugin.';
            break;
        }
    }
    return $notices;
} );

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
