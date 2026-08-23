<?php
/**
 * Module Name: Mail Log
 * Description: Registreert welke e-mails de site verstuurt en of dat lukt. Handig als een klant zegt geen mail te krijgen.
 * Category: tools
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const DP_TOOLBOX_MAILLOG_MAX = 200;

function dp_toolbox_maillog_table() {
    global $wpdb;
    return $wpdb->prefix . 'dp_mail_log';
}

function dp_toolbox_maillog_ensure_table() {
    global $wpdb;

    if ( get_option( 'dp_toolbox_maillog_table_version' ) === '1.0' ) {
        return;
    }

    $table   = dp_toolbox_maillog_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recipient varchar(255) NOT NULL DEFAULT '',
        subject varchar(255) NOT NULL DEFAULT '',
        status varchar(10) NOT NULL DEFAULT 'ok',
        error text NULL,
        created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status),
        KEY created (created)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'dp_toolbox_maillog_table_version', '1.0' );
}
add_action( 'admin_init', 'dp_toolbox_maillog_ensure_table' );

/* ================================================================== */
/*  Registreren                                                        */
/* ================================================================== */

/**
 * Ontvangers kunnen als string of als array binnenkomen.
 */
function dp_toolbox_maillog_ontvangers( $to ) {
    if ( is_array( $to ) ) {
        $to = implode( ', ', $to );
    }
    return substr( (string) $to, 0, 255 );
}

/**
 * Bewust géén berichttekst: die kan persoonsgegevens, wachtwoordlinks of
 * bestelgegevens bevatten. Ontvanger, onderwerp en uitkomst zijn genoeg om te
 * beantwoorden of een mail de deur uit ging.
 */
function dp_toolbox_maillog_schrijf( $to, $subject, $status, $error = '' ) {
    global $wpdb;

    $wpdb->insert(
        dp_toolbox_maillog_table(),
        [
            'recipient' => dp_toolbox_maillog_ontvangers( $to ),
            'subject'   => substr( (string) $subject, 0, 255 ),
            'status'    => 'fail' === $status ? 'fail' : 'ok',
            'error'     => $error ? substr( (string) $error, 0, 2000 ) : '',
            'created'   => current_time( 'mysql', true ), // UTC — zie de opmerking bij het tonen.
        ],
        [ '%s', '%s', '%s', '%s', '%s' ]
    );
}

add_action( 'wp_mail_succeeded', function ( $mail_data ) {
    dp_toolbox_maillog_schrijf(
        $mail_data['to'] ?? '',
        $mail_data['subject'] ?? '',
        'ok'
    );
} );

add_action( 'wp_mail_failed', function ( $error ) {
    if ( ! $error instanceof WP_Error ) {
        return;
    }

    $data = $error->get_error_data();
    $data = is_array( $data ) ? $data : [];

    dp_toolbox_maillog_schrijf(
        $data['to'] ?? '',
        $data['subject'] ?? '',
        'fail',
        $error->get_error_message()
    );
} );

/**
 * Ouder dan de laatste 200 regels hoeft niet bewaard te blijven.
 */
add_action( 'dp_toolbox_maillog_opruimen', function () {
    global $wpdb;
    $table = dp_toolbox_maillog_table();

    $grens = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
        DP_TOOLBOX_MAILLOG_MAX
    ) );

    if ( $grens > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $grens ) );
    }
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'dp_toolbox_maillog_opruimen' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dp_toolbox_maillog_opruimen' );
    }
} );

/* ================================================================== */
/*  Gegevens ophalen                                                   */
/* ================================================================== */

function dp_toolbox_maillog_regels( $limiet = 50, $alleen_fouten = false ) {
    global $wpdb;
    $table = dp_toolbox_maillog_table();
    $where = $alleen_fouten ? "WHERE status = 'fail'" : '';

    return (array) $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d", (int) $limiet ),
        ARRAY_A
    );
}

function dp_toolbox_maillog_aantallen() {
    global $wpdb;
    $table = dp_toolbox_maillog_table();
    $dag   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

    return [
        'dag'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created >= %s", $dag ) ),
        'fouten'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'fail'" ),
        'totaal'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
        'laatste' => (string) $wpdb->get_var( "SELECT created FROM {$table} ORDER BY id DESC LIMIT 1" ),
    ];
}

/* ================================================================== */
/*  AJAX                                                               */
/* ================================================================== */

add_action( 'wp_ajax_dp_toolbox_maillog_wissen', function () {
    check_ajax_referer( 'dp_toolbox_maillog', 'nonce' );

    if ( ! current_user_can( 'manage_options' )
        || ( function_exists( 'dp_toolbox_is_dp_user' ) && ! dp_toolbox_is_dp_user() ) ) {
        wp_send_json_error( 'Geen toegang.' );
    }

    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE " . dp_toolbox_maillog_table() );

    wp_send_json_success();
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
