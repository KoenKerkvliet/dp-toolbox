<?php
/**
 * Module Name: SMTP Mailer
 * Description: Configureer een SMTP-server voor betrouwbare e-mailverzending vanuit WordPress.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Encryption helpers                                                 */
/* ------------------------------------------------------------------ */

function dp_toolbox_smtp_encrypt( $value ) {
    if ( empty( $value ) ) {
        return '';
    }
    $key = wp_salt( 'auth' );
    $iv  = substr( md5( $key ), 0, 16 );

    $encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );
    return $encrypted !== false ? base64_encode( $encrypted ) : '';
}

function dp_toolbox_smtp_decrypt( $value ) {
    if ( empty( $value ) ) {
        return '';
    }
    $key = wp_salt( 'auth' );
    $iv  = substr( md5( $key ), 0, 16 );

    $decoded   = base64_decode( $value );
    $decrypted = openssl_decrypt( $decoded, 'AES-256-CBC', $key, 0, $iv );
    return $decrypted !== false ? $decrypted : '';
}

/* ------------------------------------------------------------------ */
/*  Get SMTP settings                                                  */
/* ------------------------------------------------------------------ */

function dp_toolbox_smtp_get_settings() {
    $defaults = [
        'host'       => 'smtp.emailit.com',
        'port'       => 587,
        'encryption' => 'tls',
        'auth'       => true,
        'username'   => 'emailit',
        'password'   => '',
        'from_email' => '',
        'from_name'  => '',
    ];

    $saved = get_option( 'dp_toolbox_smtp_settings', [] );
    $settings = wp_parse_args( $saved, $defaults );

    // Decrypt password
    if ( ! empty( $settings['password'] ) ) {
        $settings['password'] = dp_toolbox_smtp_decrypt( $settings['password'] );
    }

    return $settings;
}

/* ------------------------------------------------------------------ */
/*  Override PHPMailer with SMTP settings                               */
/* ------------------------------------------------------------------ */

add_action( 'phpmailer_init', function ( $phpmailer ) {
    $smtp = dp_toolbox_smtp_get_settings();

    if ( empty( $smtp['host'] ) ) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = $smtp['host'];
    $phpmailer->Port       = absint( $smtp['port'] );
    $phpmailer->SMTPSecure = $smtp['encryption'];
    $phpmailer->SMTPAuth   = (bool) $smtp['auth'];

    if ( $smtp['auth'] ) {
        $phpmailer->Username = $smtp['username'];
        $phpmailer->Password = $smtp['password'];
    }

    if ( ! empty( $smtp['from_email'] ) ) {
        $phpmailer->From = $smtp['from_email'];
    }
    if ( ! empty( $smtp['from_name'] ) ) {
        $phpmailer->FromName = $smtp['from_name'];
    }
} );

/* ------------------------------------------------------------------ */
/*  Override From headers (wp_mail filters)                            */
/* ------------------------------------------------------------------ */

add_filter( 'wp_mail_from', function ( $email ) {
    $smtp = dp_toolbox_smtp_get_settings();
    return ! empty( $smtp['from_email'] ) ? $smtp['from_email'] : $email;
} );

add_filter( 'wp_mail_from_name', function ( $name ) {
    $smtp = dp_toolbox_smtp_get_settings();
    return ! empty( $smtp['from_name'] ) ? $smtp['from_name'] : $name;
} );

/* ------------------------------------------------------------------ */
/*  AJAX: send test email                                              */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_smtp_test', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }

    check_ajax_referer( 'dp_toolbox_smtp', 'nonce' );

    $to = sanitize_email( $_POST['to'] ?? '' );
    if ( ! is_email( $to ) ) {
        wp_send_json_error( 'Ongeldig e-mailadres.' );
    }

    $subject = 'DP Toolbox — SMTP Testmail';
    $message = 'Dit is een testbericht van DP Toolbox SMTP Mailer.' . "\n\n"
             . 'Als je dit bericht ontvangt, werkt de SMTP-configuratie correct.' . "\n"
             . 'Verzonden op: ' . current_time( 'Y-m-d H:i:s' );

    // Capture PHPMailer errors
    global $phpmailer;
    $result = wp_mail( $to, $subject, $message );

    if ( $result ) {
        wp_send_json_success( [ 'message' => 'Testmail succesvol verzonden naar ' . $to ] );
    } else {
        $error = '';
        if ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
            $error = $phpmailer->ErrorInfo;
        }
        wp_send_json_error( 'Verzending mislukt.' . ( $error ? ' Fout: ' . $error : '' ) );
    }
} );

/* ------------------------------------------------------------------ */
/*  Conflict detection                                                 */
/* ------------------------------------------------------------------ */

add_filter( 'dp_toolbox_module_notices', function ( $notices ) {
    $smtp_plugins = [
        'wp-mail-smtp/wp_mail_smtp.php',
        'fluent-smtp/fluent-smtp.php',
        'post-smtp/postman-smtp.php',
        'easy-wp-smtp/easy-wp-smtp.php',
    ];

    foreach ( $smtp_plugins as $plugin ) {
        if ( is_plugin_active( $plugin ) ) {
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
            $notices['smtp'] = 'Mogelijk conflict met ' . $data['Name'] . '. Gebruik slechts één SMTP-plugin.';
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
