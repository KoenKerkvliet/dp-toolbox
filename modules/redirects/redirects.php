<?php
/**
 * Module Name: Redirects
 * Description: Beheer 301/302 redirects vanuit WordPress — zonder extra plugin.
 * Category: content
 * Version: 1.2.0
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

/**
 * Bouw het regex-patroon voor een regel.
 *
 * Het scheidingsteken wordt in het patroon zelf ge-escaped. Zonder dat levert
 * een 'van'-waarde die het scheidingsteken bevat een ongeldig patroon op, en
 * omdat preg_match dan false teruggeeft matcht die regel gewoon nooit — stil,
 * zonder melding, en dus lastig te vinden.
 */
/**
 * Breng een pad op de vorm waarop we regels indexeren én verzoeken opzoeken.
 *
 * Dit moet aan beide kanten hetzelfde gebeuren. Ging het eerder mis bij de
 * homepage: een regel vanaf `/` werd geïndexeerd als lege sleutel (`rtrim`
 * haalt de enige slash weg) terwijl een verzoek werd opgezocht als `/`. Zo'n
 * omleiding deed dus niets, zonder enige melding.
 */
function dp_toolbox_redirects_pad( $pad ) {
    $pad = rtrim( (string) $pad, '/' );

    return strtolower( '' === $pad ? '/' : $pad );
}

function dp_toolbox_redirects_patroon( $van ) {
    return '#^' . str_replace( '#', '\#', $van ) . '$#i';
}

add_action( 'template_redirect', function () {
    if ( is_admin() ) {
        return;
    }

    $redirects = dp_toolbox_redirects_get_all();
    if ( empty( $redirects ) ) {
        return;
    }

    $request_path = dp_toolbox_redirects_pad( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

    $gevonden = null;
    $target   = '';

    /*
     * Exacte regels eerst, via een directe lookup in plaats van een lus. Die
     * vormen in de praktijk het leeuwendeel, en deze code draait op élke
     * front-end request — ook op pagina's die gewoon bestaan.
     */
    $exact = [];
    foreach ( $redirects as $id => $rule ) {
        if ( empty( $rule['from'] ) || empty( $rule['to'] ) || empty( $rule['active'] ) || ! empty( $rule['regex'] ) ) {
            continue;
        }
        $exact[ dp_toolbox_redirects_pad( $rule['from'] ) ] = $id;
    }

    if ( isset( $exact[ $request_path ] ) ) {
        $gevonden = $exact[ $request_path ];
        $target   = $redirects[ $gevonden ]['to'];
    } else {
        // Pas daarna de regex-regels langs.
        foreach ( $redirects as $id => $rule ) {
            if ( empty( $rule['from'] ) || empty( $rule['to'] ) || empty( $rule['active'] ) || empty( $rule['regex'] ) ) {
                continue;
            }
            $patroon = dp_toolbox_redirects_patroon( rtrim( $rule['from'], '/' ) );
            if ( @preg_match( $patroon, $request_path ) === 1 ) {
                $gevonden = $id;
                $target   = preg_replace( $patroon, $rule['to'], $request_path );
                break;
            }
        }
    }

    if ( null === $gevonden ) {
        return;
    }

    /*
     * Wijst de omleiding naar het adres waar we al zijn? Dan niet omleiden.
     * Zonder deze controle levert een regel als `/` -> `/` een oneindige lus op,
     * en dat is precies het soort regel dat je per ongeluk maakt zodra omleiden
     * vanaf de homepage werkt.
     */
    $doel_pad = parse_url( $target, PHP_URL_PATH );
    if ( null !== $doel_pad && dp_toolbox_redirects_pad( $doel_pad ) === $request_path ) {
        $doel_host = parse_url( $target, PHP_URL_HOST );
        if ( ! $doel_host || strtolower( $doel_host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
            return;
        }
    }

    /*
     * De teller pas bijwerken op 'shutdown'. Die draait ná het versturen van de
     * redirect, dus de bezoeker — of de crawler die tijdens een migratie je hele
     * oude URL-set aftikt — wacht niet op een databaseschrijfactie.
     */
    add_action( 'shutdown', function () use ( $gevonden ) {
        $actueel = dp_toolbox_redirects_get_all();
        if ( ! isset( $actueel[ $gevonden ] ) ) {
            return;
        }
        $actueel[ $gevonden ]['hits']     = ( $actueel[ $gevonden ]['hits'] ?? 0 ) + 1;
        $actueel[ $gevonden ]['last_hit'] = current_time( 'mysql' );
        update_option( 'dp_toolbox_redirects', $actueel, false );
    } );

    $code = (int) ( $redirects[ $gevonden ]['type'] ?? 301 );
    wp_redirect( $target, $code );
    exit;
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

    // Validate regex — via dezelfde patroonbouwer als de matching, zodat
    // validatie en uitvoering nooit uit elkaar kunnen lopen.
    if ( $regex && @preg_match( dp_toolbox_redirects_patroon( $from ), '' ) === false ) {
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
