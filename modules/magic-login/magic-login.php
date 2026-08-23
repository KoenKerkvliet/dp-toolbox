<?php
/**
 * Module Name: Magic Login
 * Description: Laat leden inloggen via een eenmalige link per e-mail, zonder wachtwoord. Beheerders blijven op wachtwoord.
 * Category: security
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ================================================================== */
/*  Instellingen                                                       */
/* ================================================================== */

function dp_toolbox_ml_defaults() {
    return [
        'roles'         => [ 'author' ],
        'ttl'           => 15,   // minuten
        'confirm_step'  => 1,    // bevestigknop i.p.v. direct inloggen op GET
        'redirect'      => '',   // leeg = home_url('/')
        'show_on_login' => 1,    // blok tonen op de wp-login pagina
        'max_per_hour'  => 3,    // aanvragen per account per uur
        'mail_subject'  => 'Je inloglink voor {site}',
        'mail_body'     => "Hallo {naam},\n\nHier is je persoonlijke inloglink voor {site}:\n\n{link}\n\nDe link is {geldigheid} minuten geldig en werkt één keer.\nHeb je hier niet om gevraagd? Dan hoef je niets te doen.\n\nGroet,\n{site}",
    ];
}

function dp_toolbox_ml_get_settings() {
    $saved = get_option( 'dp_toolbox_magic_login', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }
    return array_merge( dp_toolbox_ml_defaults(), $saved );
}

function dp_toolbox_ml_setting( $key ) {
    $s = dp_toolbox_ml_get_settings();
    return $s[ $key ] ?? null;
}

/**
 * Rollen die een inloglink mogen gebruiken.
 *
 * Rollen met `manage_options` (beheerders) worden hier bewust uitgesloten: het
 * risico van een inloglink zit in overname van de mailbox, en dat risico wil je
 * niet op een account met volledige sitebeheer-rechten. Wie dat toch wil, kan
 * het via de filter `dp_toolbox_ml_selectable_roles` forceren.
 */
function dp_toolbox_ml_selectable_roles() {
    $roles = [];

    foreach ( wp_roles()->roles as $slug => $role ) {
        if ( ! empty( $role['capabilities']['manage_options'] ) ) {
            continue;
        }
        $roles[ $slug ] = translate_user_role( $role['name'] );
    }

    return apply_filters( 'dp_toolbox_ml_selectable_roles', $roles );
}

/**
 * Mag deze gebruiker een inloglink krijgen?
 */
function dp_toolbox_ml_user_allowed( $user ) {
    if ( ! $user instanceof WP_User || ! $user->exists() ) {
        return false;
    }

    // Harde grens: nooit voor accounts die de site kunnen beheren.
    if ( user_can( $user, 'manage_options' ) ) {
        return apply_filters( 'dp_toolbox_ml_user_allowed', false, $user );
    }

    $allowed   = (array) dp_toolbox_ml_setting( 'roles' );
    $selectable = dp_toolbox_ml_selectable_roles();
    $allowed   = array_intersect( $allowed, array_keys( $selectable ) );
    $has_role  = (bool) array_intersect( $allowed, (array) $user->roles );

    return apply_filters( 'dp_toolbox_ml_user_allowed', $has_role, $user );
}

/* ================================================================== */
/*  Helpers                                                            */
/* ================================================================== */

function dp_toolbox_ml_client_ip() {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
    return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Tel een poging en meld of de limiet overschreden is.
 *
 * @return bool true = limiet bereikt (blokkeren).
 */
function dp_toolbox_ml_rate_limited( $bucket, $limit, $window = HOUR_IN_SECONDS ) {
    $key   = 'dp_ml_rl_' . md5( $bucket );
    $count = (int) get_transient( $key );

    if ( $count >= $limit ) {
        return true;
    }

    set_transient( $key, $count + 1, $window );
    return false;
}

function dp_toolbox_ml_log( $action, $args = [] ) {
    if ( function_exists( 'dp_toolbox_al_log' ) ) {
        dp_toolbox_al_log( 'login', $action, $args );
    }
}

/**
 * Waar komt iemand na het inloggen terecht?
 */
function dp_toolbox_ml_destination( $requested = '' ) {
    $fallback = dp_toolbox_ml_setting( 'redirect' );
    $fallback = $fallback ? $fallback : home_url( '/' );

    if ( $requested ) {
        return wp_validate_redirect( $requested, $fallback );
    }

    return wp_validate_redirect( $fallback, home_url( '/' ) );
}

/* ================================================================== */
/*  Aanvraag: token maken en mailen                                    */
/* ================================================================== */

/**
 * Verwerk een aanvraag. Geeft altijd hetzelfde resultaat terug, ongeacht of het
 * account bestaat — anders is dit formulier een manier om e-mailadressen af te
 * tasten (user enumeration).
 *
 * @return true|WP_Error true bij "verwerkt", WP_Error alleen bij rate limiting.
 */
function dp_toolbox_ml_handle_request( $email, $redirect_to = '' ) {
    $ip = dp_toolbox_ml_client_ip();

    // Ruwe rem op de bron, los van welk adres er ingevuld wordt.
    if ( dp_toolbox_ml_rate_limited( 'ip:' . $ip, 12 ) ) {
        return new WP_Error( 'dp_ml_throttled', 'Er zijn te veel aanvragen gedaan vanaf dit apparaat. Probeer het over een uur opnieuw.' );
    }

    $email = sanitize_email( $email );
    if ( ! is_email( $email ) ) {
        return true; // stilhouden
    }

    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        $user = get_user_by( 'login', $email );
    }

    if ( ! $user || ! dp_toolbox_ml_user_allowed( $user ) ) {
        return true; // stilhouden
    }

    $max = max( 1, (int) dp_toolbox_ml_setting( 'max_per_hour' ) );
    if ( dp_toolbox_ml_rate_limited( 'user:' . $user->ID, $max ) ) {
        return true; // stilhouden — anders verklap je dat het account bestaat
    }

    $ttl   = max( 5, min( 120, (int) dp_toolbox_ml_setting( 'ttl' ) ) );
    $token = bin2hex( random_bytes( 32 ) );

    // Alleen de hash gaat de database in. Een databaselek is dan geen inloglek.
    update_user_meta( $user->ID, '_dp_magic_login', [
        'hash'     => hash( 'sha256', $token ),
        'expires'  => time() + ( $ttl * MINUTE_IN_SECONDS ),
        'redirect' => $redirect_to ? esc_url_raw( $redirect_to ) : '',
        'ip'       => $ip,
    ] );

    $url = add_query_arg( [
        'dp-magic-login' => $token,
        'uid'            => $user->ID,
    ], home_url( '/' ) );

    dp_toolbox_ml_send_mail( $user, $url, $ttl );

    dp_toolbox_ml_log( 'Inloglink aangevraagd', [
        'object_type' => 'user',
        'object_id'   => $user->ID,
        'object_name' => $user->user_login,
        'details'     => 'Geldig tot ' . wp_date( 'H:i', time() + ( $ttl * MINUTE_IN_SECONDS ) ),
    ] );

    return true;
}

function dp_toolbox_ml_send_mail( $user, $url, $ttl ) {
    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $name = $user->first_name ? $user->first_name : $user->display_name;

    $replace = [
        '{naam}'       => $name,
        '{link}'       => $url,
        '{site}'       => $site,
        '{geldigheid}' => $ttl,
    ];

    $subject = strtr( (string) dp_toolbox_ml_setting( 'mail_subject' ), $replace );
    $body    = strtr( (string) dp_toolbox_ml_setting( 'mail_body' ), $replace );

    /**
     * Laat de mail per site aanpassen zonder de module te hoeven forken.
     */
    $subject = apply_filters( 'dp_toolbox_ml_mail_subject', $subject, $user, $url );
    $body    = apply_filters( 'dp_toolbox_ml_mail_body', $body, $user, $url );

    wp_mail( $user->user_email, $subject, $body );
}

/* ================================================================== */
/*  Verzilveren: token controleren en inloggen                         */
/* ================================================================== */

/**
 * @return WP_User|WP_Error
 */
function dp_toolbox_ml_verify( $uid, $token ) {
    $generic = new WP_Error( 'dp_ml_invalid', 'Deze inloglink werkt niet meer. Vraag hieronder een nieuwe aan.' );

    if ( dp_toolbox_ml_rate_limited( 'verify:' . dp_toolbox_ml_client_ip(), 20 ) ) {
        return new WP_Error( 'dp_ml_throttled', 'Er zijn te veel pogingen gedaan. Probeer het over een uur opnieuw.' );
    }

    $uid = absint( $uid );
    if ( ! $uid || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
        return $generic;
    }

    $user = get_user_by( 'id', $uid );
    if ( ! $user || ! dp_toolbox_ml_user_allowed( $user ) ) {
        return $generic;
    }

    $stored = get_user_meta( $uid, '_dp_magic_login', true );
    if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
        return $generic;
    }

    if ( empty( $stored['expires'] ) || time() > (int) $stored['expires'] ) {
        delete_user_meta( $uid, '_dp_magic_login' );
        return $generic;
    }

    if ( ! hash_equals( (string) $stored['hash'], hash( 'sha256', $token ) ) ) {
        return $generic;
    }

    return $user;
}

/**
 * Verzilver het token en log de gebruiker in. Eenmalig: het token wordt gewist.
 */
function dp_toolbox_ml_consume( $uid, $token ) {
    $user = dp_toolbox_ml_verify( $uid, $token );
    if ( is_wp_error( $user ) ) {
        return $user;
    }

    $stored = get_user_meta( $user->ID, '_dp_magic_login', true );
    $target = is_array( $stored ) && ! empty( $stored['redirect'] ) ? $stored['redirect'] : '';

    delete_user_meta( $user->ID, '_dp_magic_login' );

    wp_set_auth_cookie( $user->ID, true, is_ssl() );
    wp_set_current_user( $user->ID );

    // Zodat AIOS, LiteSpeed en de Activity Log hun eigen login-hooks krijgen.
    do_action( 'wp_login', $user->user_login, $user );

    dp_toolbox_ml_log( 'Ingelogd via inloglink', [
        'object_type' => 'user',
        'object_id'   => $user->ID,
        'object_name' => $user->user_login,
    ] );

    return dp_toolbox_ml_destination( $target );
}

/* ================================================================== */
/*  Requesthandlers                                                    */
/* ================================================================== */

add_action( 'init', function () {

    /*
     * Pagina's met een statusmelding of een inloglink nooit uit de cache
     * serveren — anders krijgt de volgende bezoeker andermans melding te zien.
     */
    if ( isset( $_GET['dp-ml'] ) || isset( $_GET['dp-magic-login'] ) ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'DP Toolbox Magic Login' );
        nocache_headers();
    }

    /* ---- 1. Aanvraagformulier verzonden ---- */
    if ( isset( $_POST['dp_ml_action'] ) && 'request' === $_POST['dp_ml_action'] ) {

        // Honeypot: bots vullen ieder veld in, mensen zien dit niet.
        if ( ! empty( $_POST['dp_ml_website'] ) ) {
            wp_safe_redirect( dp_toolbox_ml_back_url( 'sent' ) );
            exit;
        }

        $email    = isset( $_POST['dp_ml_email'] ) ? sanitize_email( wp_unslash( $_POST['dp_ml_email'] ) ) : '';
        $redirect = isset( $_POST['dp_ml_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['dp_ml_redirect'] ) ) : '';

        $result = dp_toolbox_ml_handle_request( $email, $redirect );
        $status = is_wp_error( $result ) ? 'throttled' : 'sent';

        wp_safe_redirect( dp_toolbox_ml_back_url( $status ) );
        exit;
    }

    /* ---- 2. Bevestigknop op de tussenpagina ---- */
    if ( isset( $_POST['dp_ml_action'] ) && 'confirm' === $_POST['dp_ml_action'] ) {
        $uid   = isset( $_POST['dp_ml_uid'] ) ? absint( $_POST['dp_ml_uid'] ) : 0;
        $token = isset( $_POST['dp_ml_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dp_ml_token'] ) ) : '';

        $result = dp_toolbox_ml_consume( $uid, $token );

        if ( is_wp_error( $result ) ) {
            dp_toolbox_ml_render_page( 'Inloggen niet gelukt', '<p>' . esc_html( $result->get_error_message() ) . '</p>' . dp_toolbox_ml_form_html() );
        }

        wp_safe_redirect( $result );
        exit;
    }

    /* ---- 3. Link uit de e-mail geopend ---- */
    if ( isset( $_GET['dp-magic-login'] ) ) {
        $token = sanitize_text_field( wp_unslash( $_GET['dp-magic-login'] ) );
        $uid   = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;

        if ( is_user_logged_in() && get_current_user_id() === $uid ) {
            wp_safe_redirect( dp_toolbox_ml_destination() );
            exit;
        }

        // Zonder bevestigstap loggen we meteen in.
        if ( ! dp_toolbox_ml_setting( 'confirm_step' ) ) {
            $result = dp_toolbox_ml_consume( $uid, $token );

            if ( is_wp_error( $result ) ) {
                dp_toolbox_ml_render_page( 'Inloggen niet gelukt', '<p>' . esc_html( $result->get_error_message() ) . '</p>' . dp_toolbox_ml_form_html() );
            }

            wp_safe_redirect( $result );
            exit;
        }

        /*
         * Mét bevestigstap: alleen kijken of het token klopt, niet verzilveren.
         * Mailscanners (Outlook, bedrijfsfilters) openen links automatisch — een
         * GET mag het token dus niet opmaken, anders is de link al verbruikt
         * voordat de ontvanger erop klikt.
         */
        $user = dp_toolbox_ml_verify( $uid, $token );

        if ( is_wp_error( $user ) ) {
            dp_toolbox_ml_render_page( 'Inloggen niet gelukt', '<p>' . esc_html( $user->get_error_message() ) . '</p>' . dp_toolbox_ml_form_html() );
        }

        $name = $user->first_name ? $user->first_name : $user->display_name;

        ob_start();
        ?>
        <p class="dp-ml-lead">Welkom terug, <?php echo esc_html( $name ); ?>.</p>
        <form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
            <input type="hidden" name="dp_ml_action" value="confirm">
            <input type="hidden" name="dp_ml_uid" value="<?php echo esc_attr( $user->ID ); ?>">
            <input type="hidden" name="dp_ml_token" value="<?php echo esc_attr( $token ); ?>">
            <button type="submit" class="dp-ml-btn">Inloggen</button>
        </form>
        <?php
        dp_toolbox_ml_render_page( 'Nog één klik', ob_get_clean() );
    }
}, 1 );

/**
 * De pagina waar het formulier op staat. Wordt als verborgen veld meegestuurd,
 * zodat we na het versturen terugkeren op precies dezelfde plek — ook als de
 * browser geen referer meestuurt.
 */
function dp_toolbox_ml_current_url() {
    $host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

    if ( ! $host ) {
        return home_url( '/' );
    }

    return esc_url_raw( set_url_scheme( '//' . $host . $uri ) );
}

/**
 * URL om na een aanvraag naar terug te keren, met statusmelding.
 */
function dp_toolbox_ml_back_url( $status ) {
    $posted = isset( $_POST['dp_ml_return'] ) ? esc_url_raw( wp_unslash( $_POST['dp_ml_return'] ) ) : '';
    $base   = $posted ? $posted : (string) wp_get_referer();
    $base   = wp_validate_redirect( $base, home_url( '/' ) );
    $base   = remove_query_arg( [ 'dp-ml', 'dp-magic-login', 'uid' ], $base );

    return add_query_arg( 'dp-ml', $status, $base );
}

/* ================================================================== */
/*  Formulier                                                          */
/* ================================================================== */

function dp_toolbox_ml_notice_html() {
    if ( empty( $_GET['dp-ml'] ) ) {
        return '';
    }

    $status = sanitize_key( wp_unslash( $_GET['dp-ml'] ) );

    if ( 'sent' === $status ) {
        return '<p class="dp-ml-notice dp-ml-notice--ok">Is dit adres bij ons bekend? Dan staat er nu een inloglink in je mailbox. Kijk ook even in je ongewenste mail.</p>';
    }

    if ( 'throttled' === $status ) {
        return '<p class="dp-ml-notice dp-ml-notice--warn">Er zijn te veel aanvragen gedaan. Probeer het over een uur opnieuw.</p>';
    }

    return '';
}

function dp_toolbox_ml_form_html( $redirect_to = '' ) {
    $action = home_url( '/' );

    ob_start();
    ?>
    <div class="dp-ml">
        <?php echo dp_toolbox_ml_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <form class="dp-ml-form" method="post" action="<?php echo esc_url( $action ); ?>">
            <input type="hidden" name="dp_ml_action" value="request">
            <input type="hidden" name="dp_ml_return" value="<?php echo esc_attr( dp_toolbox_ml_current_url() ); ?>">
            <?php if ( $redirect_to ) : ?>
                <input type="hidden" name="dp_ml_redirect" value="<?php echo esc_attr( $redirect_to ); ?>">
            <?php endif; ?>

            <label class="dp-ml-label" for="dp-ml-email">Inloggen zonder wachtwoord</label>
            <p class="dp-ml-help">Vul je e-mailadres in, dan sturen we je een link waarmee je direct binnen bent.</p>

            <input class="dp-ml-input" type="email" id="dp-ml-email" name="dp_ml_email"
                   autocomplete="email" required placeholder="jouw@email.nl">

            <div class="dp-ml-hp" aria-hidden="true">
                <label>Laat dit veld leeg
                    <input type="text" name="dp_ml_website" tabindex="-1" autocomplete="off">
                </label>
            </div>

            <button type="submit" class="dp-ml-btn">Stuur mij een inloglink</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function dp_toolbox_ml_styles() {
    return '
    .dp-ml { max-width: 360px; margin: 0 auto; }
    .dp-ml-form { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 22px 24px; text-align: left; }
    .dp-ml-label { display: block; font-weight: 600; font-size: 15px; margin-bottom: 4px; color: #1d2327; }
    .dp-ml-help { margin: 0 0 14px; font-size: 13px; line-height: 1.5; color: #646970; }
    .dp-ml-input { width: 100%; box-sizing: border-box; padding: 10px 12px; font-size: 15px;
        border: 1px solid #c3c4c7; border-radius: 6px; margin-bottom: 12px; background: #fff; color: #1d2327; }
    .dp-ml-input:focus { outline: 2px solid #281E5D; outline-offset: 1px; border-color: #281E5D; }
    .dp-ml-btn { display: block; width: 100%; padding: 11px 16px; font-size: 15px; font-weight: 600;
        color: #fff; background: #281E5D; border: none; border-radius: 6px; cursor: pointer; }
    .dp-ml-btn:hover { background: #4a3a8a; }
    .dp-ml-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .dp-ml-notice { margin: 0 0 14px; padding: 10px 12px; border-radius: 6px; font-size: 13px; line-height: 1.5; }
    .dp-ml-notice--ok { background: #edfaef; border-left: 3px solid #00a32a; color: #1d2327; }
    .dp-ml-notice--warn { background: #fcf9e8; border-left: 3px solid #dba617; color: #1d2327; }
    .dp-ml-lead { margin: 0 0 16px; font-size: 16px; color: #1d2327; }
    ';
}

/* ---- Shortcode ---- */

add_shortcode( 'dp_magic_login', function ( $atts ) {
    if ( is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts( [ 'redirect' => '' ], $atts, 'dp_magic_login' );

    return '<style>' . dp_toolbox_ml_styles() . '</style>' . dp_toolbox_ml_form_html( $atts['redirect'] );
} );

/* ---- Blok op de wp-login pagina ---- */

add_action( 'login_enqueue_scripts', function () {
    if ( ! dp_toolbox_ml_setting( 'show_on_login' ) ) {
        return;
    }
    echo '<style>' . dp_toolbox_ml_styles() . ' #login .dp-ml { margin-top: 20px; }</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );

add_action( 'login_footer', function () {
    if ( ! dp_toolbox_ml_setting( 'show_on_login' ) ) {
        return;
    }

    $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

    $html = dp_toolbox_ml_form_html( $redirect );
    ?>
    <div id="dp-ml-login-block" style="display:none;"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <script>
    (function () {
        var block = document.getElementById('dp-ml-login-block');
        var login = document.getElementById('login');
        if (!block || !login) { return; }
        block.style.display = '';
        login.appendChild(block);
    })();
    </script>
    <?php
} );

/* ================================================================== */
/*  Losse pagina (bevestiging / foutmelding)                           */
/* ================================================================== */

/**
 * Rendert een zelfstandige pagina en stopt. Bewust themaloos: dit moet ook
 * werken als de site in onderhoudsmodus staat of het thema de login afschermt.
 */
function dp_toolbox_ml_render_page( $title, $body_html ) {
    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

    nocache_headers();
    status_header( 200 );
    header( 'Content-Type: text/html; charset=utf-8' );

    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html( $title . ' — ' . $site ); ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1d2327; padding: 24px; }
        .dp-ml-page { width: 100%; max-width: 400px; text-align: center; }
        .dp-ml-page h1 { font-size: 20px; margin: 0 0 6px; }
        .dp-ml-site { font-size: 13px; color: #646970; margin: 0 0 22px; }
        .dp-ml-back { display: inline-block; margin-top: 18px; font-size: 13px; color: #646970; }
        <?php echo dp_toolbox_ml_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </style>
</head>
<body>
    <div class="dp-ml-page">
        <h1><?php echo esc_html( $title ); ?></h1>
        <p class="dp-ml-site"><?php echo esc_html( $site ); ?></p>
        <?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <a class="dp-ml-back" href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; Terug naar de website</a>
    </div>
</body>
</html>
    <?php
    exit;
}

/* ================================================================== */
/*  Opruimen                                                           */
/* ================================================================== */

add_action( 'dp_toolbox_ml_cleanup', function () {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_dp_magic_login'",
        ARRAY_A
    );

    foreach ( (array) $rows as $row ) {
        $data = maybe_unserialize( $row['meta_value'] );
        if ( ! is_array( $data ) || empty( $data['expires'] ) || time() > (int) $data['expires'] ) {
            delete_user_meta( (int) $row['user_id'], '_dp_magic_login' );
        }
    }
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'dp_toolbox_ml_cleanup' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dp_toolbox_ml_cleanup' );
    }
} );

/* ================================================================== */
/*  Admin                                                              */
/* ================================================================== */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
