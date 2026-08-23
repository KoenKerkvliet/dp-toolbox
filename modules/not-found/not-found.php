<?php
/**
 * Module Name: Niet Gevonden (404)
 * Description: Houdt bij welke pagina's bezoekers niet konden vinden, en maakt er met één klik een omleiding van.
 * Category: content
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const DP_TOOLBOX_404_MAX = 500;

function dp_toolbox_404_table() {
    global $wpdb;
    return $wpdb->prefix . 'dp_404_log';
}

function dp_toolbox_404_ensure_table() {
    global $wpdb;

    if ( get_option( 'dp_toolbox_404_table_version' ) === '1.0' ) {
        return;
    }

    $table   = dp_toolbox_404_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        path varchar(255) NOT NULL DEFAULT '',
        path_hash char(32) NOT NULL DEFAULT '',
        hits int unsigned NOT NULL DEFAULT 1,
        referer varchar(255) NOT NULL DEFAULT '',
        last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY path_hash (path_hash),
        KEY hits (hits),
        KEY resolved (resolved)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'dp_toolbox_404_table_version', '1.0' );
}
add_action( 'admin_init', 'dp_toolbox_404_ensure_table' );

/* ================================================================== */
/*  Registreren                                                        */
/* ================================================================== */

/**
 * Zet een request-URI om naar het pad zoals we het opslaan.
 *
 * Dit MOET exact overeenkomen met hoe de Redirects-module binnenkomende
 * verzoeken normaliseert — `strtolower( rtrim( path, '/' ) )` — anders maken we
 * hier omleidingen aan die daar nooit matchen, en dat merk je pas als een klant
 * meldt dat de link nog steeds stuk is.
 */
function dp_toolbox_404_pad( $request_uri ) {
    $path = parse_url( (string) $request_uri, PHP_URL_PATH );
    $path = rtrim( (string) $path, '/' );

    if ( '' === $path ) {
        $path = '/';
    }

    return strtolower( $path );
}

/**
 * Paden die we niet in de lijst willen.
 *
 * De homepage hoort daarbij. Sinds Redirects 1.2.0 zou een omleiding vanaf '/'
 * technisch werken, maar het blijft het verkeerde gereedschap: een 404 op je
 * voorpagina betekent dat de instelling daarvoor stuk is, en dat repareer je
 * bij Instellingen > Lezen. Een omleiding zou dat alleen maskeren.
 */
function dp_toolbox_404_overslaan( $path ) {
    if ( '/' === $path ) {
        return true;
    }

    /**
     * Eigen uitzonderingen per site.
     */
    return (bool) apply_filters( 'dp_toolbox_404_negeren', false, $path );
}

/*
 * Priority 20: de Redirects-module zit op template_redirect vóór ons. Wat die
 * al omleidt, hoeft hier niet als "niet gevonden" te belanden.
 */
add_action( 'template_redirect', function () {
    if ( ! is_404() || is_admin() ) {
        return;
    }
    if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
        return;
    }
    if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
        return;
    }

    $path = dp_toolbox_404_pad( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

    if ( strlen( $path ) > 255 ) {
        return;
    }

    if ( dp_toolbox_404_overslaan( $path ) ) {
        return;
    }

    $referer = wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' );
    $referer = substr( esc_url_raw( $referer ), 0, 255 );

    global $wpdb;
    $table = dp_toolbox_404_table();
    $hash  = md5( $path );

    // Bestaat de regel al? Dan alleen tellen. Insert-met-duplicate-update in
    // één query, zodat twee gelijktijdige bezoekers elkaar niet in de weg zitten.
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table} (path, path_hash, hits, referer, last_seen, resolved)
         VALUES (%s, %s, 1, %s, %s, 0)
         ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referer = VALUES(referer)",
        $path,
        $hash,
        $referer,
        current_time( 'mysql', true ) // UTC — zie de opmerking bij het tonen.
    ) );
}, 20 );

/**
 * Houd de tabel klein: alleen de drukste regels zijn interessant.
 */
add_action( 'dp_toolbox_404_opruimen', function () {
    global $wpdb;
    $table = dp_toolbox_404_table();

    $aantal = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $aantal <= DP_TOOLBOX_404_MAX ) {
        return;
    }

    $teveel = $aantal - DP_TOOLBOX_404_MAX;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} ORDER BY resolved DESC, hits ASC, last_seen ASC LIMIT %d",
        $teveel
    ) );
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'dp_toolbox_404_opruimen' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dp_toolbox_404_opruimen' );
    }
} );

/* ================================================================== */
/*  Gegevens ophalen                                                   */
/* ================================================================== */

function dp_toolbox_404_regels( $toon_opgelost = false, $limiet = 100 ) {
    global $wpdb;
    $table = dp_toolbox_404_table();

    $where = $toon_opgelost ? '' : 'WHERE resolved = 0';

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY resolved ASC, hits DESC, last_seen DESC LIMIT %d",
            (int) $limiet
        ),
        ARRAY_A
    );
}

function dp_toolbox_404_aantallen() {
    global $wpdb;
    $table = dp_toolbox_404_table();

    return [
        'open'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolved = 0" ),
        'opgelost' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolved = 1" ),
        'hits'     => (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$table} WHERE resolved = 0" ),
    ];
}

/* ================================================================== */
/*  AJAX                                                               */
/* ================================================================== */

function dp_toolbox_404_mag() {
    return current_user_can( 'manage_options' )
        && ( ! function_exists( 'dp_toolbox_is_dp_user' ) || dp_toolbox_is_dp_user() );
}

add_action( 'wp_ajax_dp_toolbox_404_redirect', function () {
    check_ajax_referer( 'dp_toolbox_404', 'nonce' );
    if ( ! dp_toolbox_404_mag() ) {
        wp_send_json_error( 'Geen toegang.' );
    }

    if ( ! function_exists( 'dp_toolbox_is_module_enabled' ) || ! dp_toolbox_is_module_enabled( 'redirects' ) ) {
        wp_send_json_error( 'Zet eerst de module Redirects aan.' );
    }

    global $wpdb;
    $id   = absint( $_POST['id'] ?? 0 );
    $naar = esc_url_raw( wp_unslash( $_POST['to'] ?? '' ) );

    if ( ! $id || '' === $naar ) {
        wp_send_json_error( 'Vul een bestemming in.' );
    }

    $rij = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . dp_toolbox_404_table() . " WHERE id = %d", $id ), ARRAY_A );
    if ( ! $rij ) {
        wp_send_json_error( 'Deze regel bestaat niet meer.' );
    }

    $redirects = (array) get_option( 'dp_toolbox_redirects', [] );

    foreach ( $redirects as $regel ) {
        if ( strtolower( rtrim( $regel['from'] ?? '', '/' ) ) === $rij['path'] ) {
            wp_send_json_error( 'Er bestaat al een omleiding voor dit adres.' );
        }
    }

    $redirects[ 'r_' . uniqid() ] = [
        'from'     => $rij['path'],
        'to'       => $naar,
        'type'     => 301,
        'regex'    => false,
        'active'   => true,
        'hits'     => 0,
        'last_hit' => '',
        'created'  => current_time( 'mysql' ),
    ];

    update_option( 'dp_toolbox_redirects', $redirects, false );

    $wpdb->update( dp_toolbox_404_table(), [ 'resolved' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

    if ( function_exists( 'dp_toolbox_al_log' ) ) {
        dp_toolbox_al_log( 'content', 'Omleiding gemaakt vanuit 404', [
            'object_name' => $rij['path'],
            'details'     => 'Naar ' . $naar,
        ] );
    }

    wp_send_json_success( [ 'message' => 'Omleiding aangemaakt.' ] );
} );

add_action( 'wp_ajax_dp_toolbox_404_verwijderen', function () {
    check_ajax_referer( 'dp_toolbox_404', 'nonce' );
    if ( ! dp_toolbox_404_mag() ) {
        wp_send_json_error( 'Geen toegang.' );
    }

    global $wpdb;
    $id = absint( $_POST['id'] ?? 0 );

    if ( $id ) {
        $wpdb->delete( dp_toolbox_404_table(), [ 'id' => $id ], [ '%d' ] );
    } else {
        $wpdb->query( "TRUNCATE TABLE " . dp_toolbox_404_table() );
    }

    wp_send_json_success();
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
