<?php
/**
 * Module Name: Magic Login
 * Description: Laat leden inloggen zonder wachtwoord: een code van zes cijfers, een eenmalige link, of allebei in dezelfde mail. Beheerders blijven op wachtwoord.
 * Category: security
 * Version: 1.4.0
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
        'ttl'           => 15,   // minuten, geldt voor de link
        'confirm_step'  => 1,    // bevestigknop i.p.v. direct inloggen op GET
        'redirect'      => '',   // leeg = home_url('/')
        'show_on_login' => 1,    // blok tonen op de wp-login pagina
        'max_per_hour'  => 3,    // aanvragen per account per uur
        'method'        => 'code', // code | link | both
        'code_ttl'      => 10,   // minuten, geldt voor de cijfercode
        'code_attempts' => 5,    // foute pogingen voordat de code sterft
        'mail_subject'  => 'Inloggen op {site}',
        'mail_body'     => "Hallo {naam},

[code]Dit is je inlogcode voor {site}:

{code}

Vul de code in op het scherm waar je hem hebt aangevraagd. De code is {codeduur} minuten geldig.[/code]

[link]Klik op de knop hieronder om in te loggen:

{link}

De link is {geldigheid} minuten geldig en werkt één keer.[/link]

Heb je hier niet om gevraagd? Dan hoef je niets te doen.

Groet,
{site}",
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

/**
 * Welke manier(en) sturen we mee: alleen de link, alleen de code, of allebei.
 */
function dp_toolbox_ml_method() {
    $m = (string) dp_toolbox_ml_setting( 'method' );

    return in_array( $m, [ 'both', 'link', 'code' ], true ) ? $m : 'both';
}

function dp_toolbox_ml_wants_link() {
    return dp_toolbox_ml_method() !== 'code';
}

function dp_toolbox_ml_wants_code() {
    return dp_toolbox_ml_method() !== 'link';
}

function dp_toolbox_ml_code_ttl() {
    return max( 3, min( 60, (int) dp_toolbox_ml_setting( 'code_ttl' ) ) );
}

function dp_toolbox_ml_code_attempts() {
    return max( 3, min( 10, (int) dp_toolbox_ml_setting( 'code_attempts' ) ) );
}

/**
 * De code is maar zes cijfers: een miljoen mogelijkheden, oftewel te raden zodra
 * je onbeperkt mag proberen. Zijn veiligheid zit dus niet in de lengte maar in
 * de spelregels: een teller op de code zelf (niet op het IP-adres, want dat
 * wisselt een aanvaller zo), een korte levensduur, eenmalig gebruik, en de
 * koppeling hieronder aan de browser die hem aanvroeg.
 *
 * Die koppeling is bewust STRENG. De code werkt alleen in het venster waar hij
 * is aangevraagd, dus onderschept iemand hem uit de mailbox, dan kan hij er
 * niets mee. Dat is precies het voordeel dat een link niet kan bieden: die moet
 * per definitie overal werken.
 *
 * Werking: bij de aanvraag zetten we een willekeurig cookie en bewaren we
 * server-side de bijbehorende gebruiker. Bij het inleveren van de code moeten
 * cookie en code allebei kloppen. Het cookie bevat zelf geen gebruikersgegevens,
 * dus het verklapt ook niets over wie er een account heeft.
 */
const DP_TOOLBOX_ML_BIND_COOKIE = 'dp_ml_bind';

function dp_toolbox_ml_set_binding( $user_id, $ttl_minutes ) {
    $secret = bin2hex( random_bytes( 16 ) );

    set_transient( 'dp_ml_bind_' . hash( 'sha256', $secret ), (int) $user_id, $ttl_minutes * MINUTE_IN_SECONDS );

    // Lax volstaat: het formulier post naar de eigen site, niet vanaf elders.
    setcookie( DP_TOOLBOX_ML_BIND_COOKIE, $secret, [
        'expires'  => time() + ( $ttl_minutes * MINUTE_IN_SECONDS ),
        'path'     => COOKIEPATH ? COOKIEPATH : '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ] );

    return $secret;
}

/**
 * De gebruiker die bij het cookie van deze browser hoort, of 0.
 */
function dp_toolbox_ml_binding_user() {
    if ( empty( $_COOKIE[ DP_TOOLBOX_ML_BIND_COOKIE ] ) ) {
        return 0;
    }

    $secret = sanitize_text_field( wp_unslash( $_COOKIE[ DP_TOOLBOX_ML_BIND_COOKIE ] ) );

    if ( ! preg_match( '/^[a-f0-9]{32}$/', $secret ) ) {
        return 0;
    }

    return (int) get_transient( 'dp_ml_bind_' . hash( 'sha256', $secret ) );
}

function dp_toolbox_ml_clear_binding() {
    if ( ! empty( $_COOKIE[ DP_TOOLBOX_ML_BIND_COOKIE ] ) ) {
        $secret = sanitize_text_field( wp_unslash( $_COOKIE[ DP_TOOLBOX_ML_BIND_COOKIE ] ) );

        if ( preg_match( '/^[a-f0-9]{32}$/', $secret ) ) {
            delete_transient( 'dp_ml_bind_' . hash( 'sha256', $secret ) );
        }
    }

    setcookie( DP_TOOLBOX_ML_BIND_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => COOKIEPATH ? COOKIEPATH : '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ] );
}

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

/**
 * Komt deze POST van de site zelf?
 *
 * Zonder deze controle kan een willekeurige externe pagina de browsers van
 * nietsvermoedende bezoekers laten posten naar dit formulier. Dat levert een
 * aanvaller niets op — de link gaat naar de mailbox van de eigenaar — maar het
 * verspreidt een mailbombardement wel over honderden IP-adressen, en juist
 * daarop rust onze limiet.
 *
 * Ontbreken beide headers (oude browser, privacy-extensie), dan laten we het
 * door: honeypot en snelheidslimiet vangen dat geval nog steeds af.
 */
function dp_toolbox_ml_same_origin() {
    $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

    foreach ( [ 'HTTP_ORIGIN', 'HTTP_REFERER' ] as $key ) {
        if ( empty( $_SERVER[ $key ] ) ) {
            continue;
        }
        $candidate = strtolower( (string) wp_parse_url( wp_unslash( $_SERVER[ $key ] ), PHP_URL_HOST ) );
        return $candidate === $host;
    }

    return true;
}

/**
 * Sluit de verbinding met de browser af, zodat trage nazorg (het versturen van
 * de mail) de bezoeker niet laat wachten.
 */
function dp_toolbox_ml_close_connection() {
    if ( function_exists( 'litespeed_finish_request' ) ) {
        litespeed_finish_request();
    } elseif ( function_exists( 'fastcgi_finish_request' ) ) {
        fastcgi_finish_request();
    }
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
/**
 * Het opgeslagen record van een aanvraag, of false.
 *
 * Een record dat helemaal op is bewaren we nog even als spoor, zodat we
 * "die code is al gebruikt" kunnen zeggen in plaats van het vage "klopt niet
 * of is verlopen". Is ook dat spoor verlopen, dan ruimen we het hier op.
 */
function dp_toolbox_ml_stored( $uid ) {
    $stored = get_user_meta( $uid, '_dp_magic_login', true );

    if ( ! is_array( $stored ) ) {
        return false;
    }

    if ( ! empty( $stored['spent_until'] ) && time() > (int) $stored['spent_until'] ) {
        delete_user_meta( $uid, '_dp_magic_login' );
        return false;
    }

    return $stored;
}

/**
 * Schrijf het record terug en ruim op zodra beide ingangen dicht zijn.
 */
function dp_toolbox_ml_store( $uid, $stored ) {
    $link_open = ! empty( $stored['hash'] ) && time() < (int) ( $stored['expires'] ?? 0 );
    $code_open = ! empty( $stored['code_hash'] ) && time() < (int) ( $stored['code_expires'] ?? 0 );

    if ( $link_open || $code_open ) {
        update_user_meta( $uid, '_dp_magic_login', $stored );
        return;
    }

    update_user_meta( $uid, '_dp_magic_login', [
        'link_used'   => (int) ( $stored['link_used'] ?? 0 ),
        'code_used'   => (int) ( $stored['code_used'] ?? 0 ),
        'spent_until' => time() + ( 30 * MINUTE_IN_SECONDS ),
    ] );
}

/**
 * Verzilver één van de twee ingangen en laat de andere met rust.
 *
 * LANDMIJN — waarom dit niet gewoon het hele record weggooit.
 * Link en code komen uit dezelfde aanvraag en stonden daarom in één record, dat
 * bij gebruik in zijn geheel verdween. Dat brak een alledaags scenario: iemand
 * vraagt op de laptop toegang aan, opent de mail op zijn telefoon en tikt daar
 * op de link. Hij is dan ingelogd op het verkeerde apparaat, en op de laptop
 * kreeg hij "deze code klopt niet of is verlopen" te zien — terwijl de code
 * prima was, alleen niet meer bestond. Elke ingang vervalt nu apart. Beide
 * blijven eenmalig, dus je levert er geen eenmaligheid mee in.
 */
function dp_toolbox_ml_spend( $uid, $what ) {
    $stored = dp_toolbox_ml_stored( $uid );

    if ( ! $stored ) {
        return;
    }

    if ( 'link' === $what ) {
        $stored['hash']      = '';
        $stored['expires']   = 0;
        $stored['link_used'] = time();
    } else {
        $stored['code_hash']    = '';
        $stored['code_expires'] = 0;
        $stored['code_used']    = time();

        // De koppeling hoort bij de code. De link mag zonder cookie werken.
        dp_toolbox_ml_clear_binding();
    }

    dp_toolbox_ml_store( $uid, $stored );
}

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

    /*
     * Alleen een token maken als er ook echt een link meegaat. Anders zou er
     * een geldige inloglink in de database staan die niemand ooit te zien
     * krijgt — onschadelijk, maar het is sleutels bijmaken voor een deur die
     * je niet gebruikt.
     */
    $token = dp_toolbox_ml_wants_link() ? bin2hex( random_bytes( 32 ) ) : '';

    $code     = '';
    $code_ttl = dp_toolbox_ml_code_ttl();

    if ( dp_toolbox_ml_wants_code() ) {
        // random_int en niet rand(): dit moet een cryptografische bron zijn.
        // str_pad houdt voorloopnullen heel - 004821 is een geldige code.
        $code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        dp_toolbox_ml_set_binding( $user->ID, $code_ttl );
    }

    // Alleen de hashes gaan de database in. Een databaselek is dan geen inloglek.
    update_user_meta( $user->ID, '_dp_magic_login', [
        'hash'          => $token ? hash( 'sha256', $token ) : '',
        'expires'       => $token ? time() + ( $ttl * MINUTE_IN_SECONDS ) : 0,
        'code_hash'     => $code ? hash( 'sha256', $code ) : '',
        'code_expires'  => $code ? time() + ( $code_ttl * MINUTE_IN_SECONDS ) : 0,
        'code_attempts' => 0,
        'redirect'      => $redirect_to ? esc_url_raw( $redirect_to ) : '',
        'ip'            => $ip,
    ] );

    $url = add_query_arg( [
        'dp-magic-login' => $token,
        'uid'            => $user->ID,
    ], home_url( '/' ) );

    /*
     * Pas versturen nadat het antwoord de deur uit is. Anders duurt een
     * aanvraag voor een bestaand account meetbaar langer dan een voor een
     * onbekend adres — de SMTP-ronde zit ertussen — en dat verschil verklapt
     * alsnog wie er lid is. Het formulier voelt er meteen ook sneller door.
     */
    add_action( 'shutdown', function () use ( $user, $url, $ttl, $code ) {
        dp_toolbox_ml_close_connection();
        dp_toolbox_ml_send_mail( $user, $url, $ttl, $code );
    }, 1 );

    dp_toolbox_ml_log( 'Inloglink aangevraagd', [
        'object_type' => 'user',
        'object_id'   => $user->ID,
        'object_name' => $user->user_login,
        'details'     => 'Geldig tot ' . wp_date( 'H:i', time() + ( $ttl * MINUTE_IN_SECONDS ) ),
    ] );

    return true;
}

/**
 * Zet de blokken [code]...[/code] en [link]...[/link] aan of uit.
 *
 * Waarom blokken en niet per regel: bij "alleen een code" moet niet alleen de
 * regel met {link} weg, maar ook de zin die hem aankondigt. Anders blijft er
 * een mail over die verwijst naar iets wat er niet meer in staat.
 */
function dp_toolbox_ml_toggle_block( $template, $tag, $keep ) {
    return preg_replace_callback(
        '/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/s',
        function ( $m ) use ( $keep ) {
            return $keep ? $m[1] : '';
        },
        $template
    );
}

/**
 * De mailtekst klaarmaken voor deze ene verzending.
 */
function dp_toolbox_ml_resolve_body( $template, $has_code ) {
    $wants_link = dp_toolbox_ml_wants_link();
    $has_blocks = false !== strpos( $template, '[code]' ) || false !== strpos( $template, '[link]' );

    if ( $has_blocks ) {
        $template = dp_toolbox_ml_toggle_block( $template, 'code', (bool) $has_code );
        $template = dp_toolbox_ml_toggle_block( $template, 'link', $wants_link );
    } else {
        /*
         * Sites die hun mailtekst al hadden aangepast kennen de blokken niet.
         * Gaat die tekst over een link die niet meer meegaat, dan valt er niets
         * te redden: dan is de standaardtekst beter dan een mail die verwijst
         * naar een knop die er niet is.
         */
        if ( ! $wants_link && false === strpos( $template, '{code}' ) ) {
            $defaults = dp_toolbox_ml_defaults();
            return dp_toolbox_ml_resolve_body( $defaults['mail_body'], $has_code );
        }

        if ( $has_code && false === strpos( $template, '{code}' ) ) {
            $template = rtrim( $template ) . "\n\n" . 'Je code: {code}';
        }

        if ( ! $has_code ) {
            $template = preg_replace( '/^.*\{code\}.*$\R?/m', '', $template );
        }

        if ( ! $wants_link ) {
            $template = preg_replace( '/^.*\{link\}.*$\R?/m', '', $template );
        }
    }

    // Weggehaalde blokken laten gaten achter; die trekken we hier weer dicht.
    $template = preg_replace( '/\R{3,}/', "\n\n", $template );

    return trim( $template );
}

/**
 * De HTML-versie van de mail.
 *
 * De code moet het eerste zijn wat iemand ziet en moet over te tikken zijn
 * zonder te turen. Dat kan alleen in HTML, en alleen met inline stijlen en
 * tabellen: mailprogramma's gooien losse stijlblokken en moderne layout weg.
 */
function dp_toolbox_ml_mail_html( $template, $name, $site, $url, $code, $ttl ) {
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    $replace = [
        '{naam}'       => esc_html( $name ),
        '{site}'       => esc_html( $site ),
        '{geldigheid}' => (int) $ttl,
        '{codeduur}'   => (int) dp_toolbox_ml_code_ttl(),
        '{code}'       => esc_html( $code ),
    ];

    $out = '';

    foreach ( preg_split( '/\R{2,}/', trim( $template ) ) as $block ) {
        $block = trim( $block );

        if ( '' === $block ) {
            continue;
        }

        if ( '{code}' === $block && $code ) {
            $out .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:22px 0;">'
                . '<tr><td align="center" style="padding:22px 12px;background:#f2f3f4;border-radius:12px;">'
                . '<div style="font-family:' . $font . ';font-size:34px;line-height:1.2;font-weight:700;letter-spacing:8px;color:#1d2327;">'
                . esc_html( $code ) . '</div></td></tr></table>';
            continue;
        }

        if ( '{link}' === $block ) {
            $out .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:22px 0;">'
                . '<tr><td align="center"><a href="' . esc_url( $url ) . '" '
                . 'style="display:inline-block;padding:13px 28px;border-radius:8px;background:#1d2327;color:#ffffff;'
                . 'font-family:' . $font . ';font-size:15px;font-weight:600;text-decoration:none;">Inloggen op '
                . esc_html( $site ) . '</a></td></tr></table>';
            continue;
        }

        $html = strtr( nl2br( esc_html( $block ) ), $replace );
        $html = str_replace( '{link}', '<a href="' . esc_url( $url ) . '" style="color:#2271b1;">' . esc_html( $url ) . '</a>', $html );

        $out .= '<p style="margin:0 0 14px;font-family:' . $font . ';font-size:15px;line-height:1.6;color:#1d2327;">' . $html . '</p>';
    }

    return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#f6f7f7;">'
        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center">'
        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" '
        . 'style="max-width:520px;background:#ffffff;border-radius:14px;">'
        . '<tr><td style="padding:30px 28px;">' . $out . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}

function dp_toolbox_ml_send_mail( $user, $url, $ttl, $code = '' ) {
    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $name = $user->first_name ? $user->first_name : $user->display_name;

    $template = dp_toolbox_ml_resolve_body( (string) dp_toolbox_ml_setting( 'mail_body' ), (bool) $code );

    $replace = [
        '{naam}'       => $name,
        '{link}'       => $url,
        '{site}'       => $site,
        '{geldigheid}' => $ttl,
        '{code}'       => $code,
        '{codeduur}'   => dp_toolbox_ml_code_ttl(),
    ];

    $subject = strtr( (string) dp_toolbox_ml_setting( 'mail_subject' ), $replace );
    $text    = strtr( $template, $replace );
    $html    = dp_toolbox_ml_mail_html( $template, $name, $site, $url, $code, $ttl );

    /**
     * Laat de mail per site aanpassen zonder de module te hoeven forken.
     */
    $subject = apply_filters( 'dp_toolbox_ml_mail_subject', $subject, $user, $url );
    $text    = apply_filters( 'dp_toolbox_ml_mail_body', $text, $user, $url );
    $html    = apply_filters( 'dp_toolbox_ml_mail_html', $html, $user, $url, $text );

    /*
     * De platte tekst gaat als alternatief mee in dezelfde mail. Dat is niet
     * alleen netjes voor wie geen HTML toont: spamfilters wegen een HTML-mail
     * zonder tekstversie zwaarder, en een inlogmail die in de spambox belandt
     * is een inlogmail die niet bestaat.
     */
    $type = function () {
        return 'text/html';
    };
    $alt = function ( $phpmailer ) use ( $text ) {
        $phpmailer->AltBody = $text;
    };

    add_filter( 'wp_mail_content_type', $type );
    add_action( 'phpmailer_init', $alt );

    wp_mail( $user->user_email, $subject, $html );

    remove_action( 'phpmailer_init', $alt );
    remove_filter( 'wp_mail_content_type', $type );
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

    $stored = dp_toolbox_ml_stored( $uid );
    if ( ! $stored ) {
        return $generic;
    }

    if ( empty( $stored['hash'] ) ) {
        if ( ! empty( $stored['link_used'] ) ) {
            return new WP_Error( 'dp_ml_link_used', 'Deze inloglink is al gebruikt. Vraag hieronder een nieuwe aan.' );
        }
        return $generic;
    }

    if ( empty( $stored['expires'] ) || time() > (int) $stored['expires'] ) {
        // Verlopen is iets anders dan gebruikt: geen link_used-spoor zetten,
        // anders krijgt het lid straks de verkeerde uitleg te lezen.
        $stored['hash']    = '';
        $stored['expires'] = 0;
        dp_toolbox_ml_store( $uid, $stored );
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

    $stored = dp_toolbox_ml_stored( $user->ID );
    $target = is_array( $stored ) && ! empty( $stored['redirect'] ) ? $stored['redirect'] : '';

    // Alleen de link opmaken. Een code uit dezelfde mail blijft geldig, zodat
    // iemand die hier per ongeluk op zijn telefoon belandt op zijn laptop
    // gewoon verder kan.
    dp_toolbox_ml_spend( $user->ID, 'link' );

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

/**
 * Verzilver een cijfercode en log de gebruiker in.
 *
 * De volgorde is bewust: eerst de browserkoppeling, dan pas de code. Zo kost een
 * poging vanuit een ander venster geen enkele van de vijf kansen — anders zou
 * iemand de code van een slachtoffer kunnen laten verlopen door hem vijf keer
 * fout in te vullen (denial of service op andermans login).
 *
 * @return string|WP_Error Doel-URL bij succes.
 */
function dp_toolbox_ml_consume_code( $code ) {
    $generic = new WP_Error( 'dp_ml_code_invalid', 'Deze code klopt niet of is verlopen. Vraag hieronder een nieuwe aan.' );

    if ( dp_toolbox_ml_rate_limited( 'verify:' . dp_toolbox_ml_client_ip(), 20 ) ) {
        return new WP_Error( 'dp_ml_throttled', 'Er zijn te veel pogingen gedaan. Probeer het over een uur opnieuw.' );
    }

    $code = preg_replace( '/\D/', '', (string) $code );

    if ( strlen( $code ) !== 6 ) {
        return $generic;
    }

    $uid = dp_toolbox_ml_binding_user();

    if ( ! $uid ) {
        return new WP_Error(
            'dp_ml_no_binding',
            'Deze code hoort bij een ander venster. Vraag een nieuwe code aan op het apparaat waar je verder wilt.'
        );
    }

    $user = get_user_by( 'id', $uid );

    if ( ! $user || ! dp_toolbox_ml_user_allowed( $user ) ) {
        return $generic;
    }

    $stored = dp_toolbox_ml_stored( $uid );

    if ( ! $stored ) {
        return new WP_Error( 'dp_ml_code_gone', 'Deze code is niet meer geldig. Vraag hieronder een nieuwe aan.' );
    }

    if ( empty( $stored['code_hash'] ) ) {
        if ( ! empty( $stored['code_used'] ) ) {
            return new WP_Error( 'dp_ml_code_used', 'Deze code is al gebruikt om in te loggen. Vraag hieronder een nieuwe aan.' );
        }
        return $generic;
    }

    if ( empty( $stored['code_expires'] ) || time() > (int) $stored['code_expires'] ) {
        $stored['code_hash']    = '';
        $stored['code_expires'] = 0;
        dp_toolbox_ml_store( $uid, $stored );
        dp_toolbox_ml_clear_binding();
        return new WP_Error( 'dp_ml_code_expired', 'Deze code is verlopen. Vraag hieronder een nieuwe aan.' );
    }

    $max      = dp_toolbox_ml_code_attempts();
    $attempts = (int) ( $stored['code_attempts'] ?? 0 );

    if ( $attempts >= $max ) {
        $stored['code_hash']    = '';
        $stored['code_expires'] = 0;
        dp_toolbox_ml_store( $uid, $stored );
        dp_toolbox_ml_clear_binding();
        return new WP_Error( 'dp_ml_code_burned', 'Te vaak misgetypt. Vraag hieronder een nieuwe code aan.' );
    }

    if ( ! hash_equals( (string) $stored['code_hash'], hash( 'sha256', $code ) ) ) {
        // De teller staat op de code zelf en niet op het IP-adres: een aanvaller
        // wisselt van IP, maar de code is na vijf pogingen hoe dan ook dood.
        $stored['code_attempts'] = $attempts + 1;
        $over = $max - $stored['code_attempts'];

        if ( $over < 1 ) {
            $stored['code_hash']    = '';
            $stored['code_expires'] = 0;
            dp_toolbox_ml_store( $uid, $stored );
            dp_toolbox_ml_clear_binding();
            return new WP_Error( 'dp_ml_code_burned', 'Te vaak misgetypt. Vraag hieronder een nieuwe code aan.' );
        }

        dp_toolbox_ml_store( $uid, $stored );

        return new WP_Error(
            'dp_ml_code_invalid',
            sprintf( 'Die code klopt niet. Je hebt nog %d %s.', $over, 1 === $over ? 'poging' : 'pogingen' )
        );
    }

    $target = ! empty( $stored['redirect'] ) ? $stored['redirect'] : '';

    // Alleen de code opmaken; een nog geldige link uit dezelfde mail blijft werken.
    dp_toolbox_ml_spend( $uid, 'code' );

    wp_set_auth_cookie( $user->ID, true, is_ssl() );
    wp_set_current_user( $user->ID );

    do_action( 'wp_login', $user->user_login, $user );

    dp_toolbox_ml_log( 'Ingelogd via cijfercode', [
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
    if ( isset( $_GET['dp-ml'] ) || isset( $_GET['dp-ml-fout'] ) || isset( $_GET['dp-magic-login'] ) ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'DP Toolbox Magic Login' );
        nocache_headers();
    }

    /* ---- 1. Aanvraagformulier verzonden ---- */
    if ( isset( $_POST['dp_ml_action'] ) && 'request' === $_POST['dp_ml_action'] ) {

        // Honeypot: bots vullen ieder veld in, mensen zien dit niet.
        // Kruislings geposte formulieren gaan om dezelfde reden stil de prullenbak in.
        if ( ! empty( $_POST['dp_ml_website'] ) || ! dp_toolbox_ml_same_origin() ) {
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

    /* ---- 1b. Cijfercode ingeleverd ---- */
    if ( isset( $_POST['dp_ml_action'] ) && 'code' === $_POST['dp_ml_action'] ) {

        if ( ! dp_toolbox_ml_same_origin() ) {
            wp_safe_redirect( dp_toolbox_ml_back_url( 'sent' ) );
            exit;
        }

        $code   = isset( $_POST['dp_ml_code'] ) ? sanitize_text_field( wp_unslash( $_POST['dp_ml_code'] ) ) : '';
        $result = dp_toolbox_ml_consume_code( $code );

        if ( is_wp_error( $result ) ) {
            $terug = dp_toolbox_ml_back_url( 'sent' );
            $terug = add_query_arg( 'dp-ml-fout', rawurlencode( $result->get_error_message() ), $terug );
            wp_safe_redirect( $terug );
            exit;
        }

        wp_safe_redirect( $result );
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
    $base   = remove_query_arg( [ 'dp-ml', 'dp-ml-fout', 'dp-magic-login', 'uid' ], $base );

    return add_query_arg( 'dp-ml', $status, $base );
}

/* ================================================================== */
/*  Formulier                                                          */
/* ================================================================== */

function dp_toolbox_ml_notice_html() {
    // Een foutmelding van de codecontrole gaat voor: die is het meest recent.
    if ( ! empty( $_GET['dp-ml-fout'] ) ) {
        $fout = sanitize_text_field( wp_unslash( $_GET['dp-ml-fout'] ) );

        return '<p class="dp-ml-notice dp-ml-notice--warn">' . esc_html( $fout ) . '</p>';
    }

    if ( empty( $_GET['dp-ml'] ) ) {
        return '';
    }

    $status = sanitize_key( wp_unslash( $_GET['dp-ml'] ) );

    if ( 'sent' === $status ) {
        $tekst = dp_toolbox_ml_wants_code()
            ? ( dp_toolbox_ml_wants_link()
                ? 'Is dit adres bij ons bekend? Dan staat er nu een mail in je inbox met een inloglink én een code. Kijk ook even bij ongewenste mail.'
                : 'Is dit adres bij ons bekend? Dan staat er nu een code van zes cijfers in je mailbox. Kijk ook even bij ongewenste mail.' )
            : 'Is dit adres bij ons bekend? Dan staat er nu een inloglink in je mailbox. Kijk ook even bij ongewenste mail.';

        return '<p class="dp-ml-notice dp-ml-notice--ok">' . esc_html( $tekst ) . '</p>';
    }

    if ( 'throttled' === $status ) {
        return '<p class="dp-ml-notice dp-ml-notice--warn">Er zijn te veel aanvragen gedaan. Probeer het over een uur opnieuw.</p>';
    }

    return '';
}

/**
 * Het codeveld. Verschijnt pas nadat er een aanvraag gedaan is, want eerder is
 * er niets in te vullen.
 *
 * Bewust géén gebruikersnaam of e-mailadres in dit formulier: welk account het
 * betreft weten we al via het cookie van de aanvraag. Zou het adres hier staan,
 * dan kon iemand een code van een ander proberen te raden.
 */
function dp_toolbox_ml_code_form_html() {
    if ( ! dp_toolbox_ml_wants_code() ) {
        return '';
    }

    $gevraagd = ! empty( $_GET['dp-ml'] ) || ! empty( $_GET['dp-ml-fout'] );

    if ( ! $gevraagd ) {
        return '';
    }

    ob_start();
    ?>
    <form class="dp-ml-form dp-ml-form--code" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
        <input type="hidden" name="dp_ml_action" value="code">
        <input type="hidden" name="dp_ml_return" value="<?php echo esc_attr( dp_toolbox_ml_current_url() ); ?>">

        <label class="dp-ml-label" for="dp-ml-code">Code uit de mail</label>
        <input class="dp-ml-input dp-ml-input--code" type="text" id="dp-ml-code" name="dp_ml_code"
               inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
               placeholder="000000" required>

        <button type="submit" class="dp-ml-btn">Inloggen met code</button>
    </form>

    <p class="dp-ml-scheiding"><span>of vraag een nieuwe aan</span></p>
    <?php
    return ob_get_clean();
}

/**
 * De inhoud van het blok: melding, uitleg en het formulier zelf.
 */
function dp_toolbox_ml_panel_html( $redirect_to = '' ) {
    ob_start();
    ?>
    <?php echo dp_toolbox_ml_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php echo dp_toolbox_ml_code_form_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <p class="dp-ml-help"><?php
        if ( dp_toolbox_ml_wants_code() && ! dp_toolbox_ml_wants_link() ) {
            echo 'Vul je e-mailadres in, dan sturen we je een code van zes cijfers. Geen wachtwoord nodig.';
        } elseif ( dp_toolbox_ml_wants_code() ) {
            echo 'Vul je e-mailadres in, dan sturen we je een inloglink én een code. Geen wachtwoord nodig.';
        } else {
            echo 'Vul je e-mailadres in, dan sturen we je een link waarmee je direct binnen bent. Geen wachtwoord nodig.';
        }
    ?></p>

    <form class="dp-ml-form" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
        <input type="hidden" name="dp_ml_action" value="request">
        <input type="hidden" name="dp_ml_return" value="<?php echo esc_attr( dp_toolbox_ml_current_url() ); ?>">
        <?php if ( $redirect_to ) : ?>
            <input type="hidden" name="dp_ml_redirect" value="<?php echo esc_attr( $redirect_to ); ?>">
        <?php endif; ?>

        <label class="dp-ml-srlabel" for="dp-ml-email">E-mailadres</label>
        <input class="dp-ml-input" type="email" id="dp-ml-email" name="dp_ml_email"
               autocomplete="email" required placeholder="jouw@email.nl">

        <div class="dp-ml-hp" aria-hidden="true">
            <label>Laat dit veld leeg
                <input type="text" name="dp_ml_website" tabindex="-1" autocomplete="off">
            </label>
        </div>

        <button type="submit" class="dp-ml-btn">Stuur mij een inloglink</button>
    </form>
    <?php
    return ob_get_clean();
}

/**
 * @param string $redirect_to Waar de gebruiker na het inloggen heen moet.
 * @param bool   $collapsible Ingeklapt achter een regel. Zo gebruiken we het op de
 *                            inlogpagina: het wachtwoordformulier blijft de hoofdroute,
 *                            de inloglink staat er rustig onder.
 */
function dp_toolbox_ml_form_html( $redirect_to = '', $collapsible = false ) {
    $panel  = dp_toolbox_ml_panel_html( $redirect_to );
    $notice = dp_toolbox_ml_notice_html();

    if ( $collapsible ) {
        // Na een aanvraag standaard open, anders mist de bezoeker de bevestiging.
        return '<div class="dp-ml"><details class="dp-ml-box"' . ( $notice ? ' open' : '' ) . '>'
            . '<summary class="dp-ml-summary">Inloggen met een inloglink</summary>'
            . '<div class="dp-ml-panel">' . $panel . '</div>'
            . '</details></div>';
    }

    return '<div class="dp-ml"><div class="dp-ml-box dp-ml-box--plain"><div class="dp-ml-panel">'
        . '<p class="dp-ml-title">Inloggen zonder wachtwoord</p>' . $panel
        . '</div></div></div>';
}

/**
 * @param string $scope Voorvoegsel voor elke selector. Leeg op de voorkant,
 *                      '#login ' op de wp-login pagina.
 *
 * LANDMIJN — waarom dat voorvoegsel er is.
 * WordPress' eigen login.min.css bevat `.login * { margin: 0; padding: 0; }`.
 * Dat is een universele reset met specificiteit (0,1,0): precies evenveel als
 * een van onze klassen, en het bestand wordt ná onze inline stijl ingeladen.
 * Bij gelijkspel wint de laatste, dus verdween al onze padding en marge — en
 * alleen die: kleuren, randen en lettergroottes bleven staan, wat het extra
 * verwarrend maakt. Een id ervoor tilt ons boven die reset uit.
 */
function dp_toolbox_ml_styles( $scope = '' ) {
    $accent = function_exists( 'dp_toolbox_branding_color' ) ? dp_toolbox_branding_color( 'accent' ) : '#281E5D';
    $hover  = function_exists( 'dp_toolbox_branding_color' ) ? dp_toolbox_branding_color( 'accent_hover' ) : '#4a3a8a';

    $css = '
    /* Geen width:100% — dat telt op bij een linkermarge en steekt dan uit. */
    {s}.dp-ml { margin: 16px auto 0; text-align: left; max-width: 420px; }
    {s}.dp-ml-box { background: #fff; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.18); overflow: hidden; }
    {s}.dp-ml-summary {
        list-style: none; cursor: pointer; -webkit-user-select: none; user-select: none;
        display: flex; align-items: center; gap: 8px;
        padding: 16px 22px; font-size: 14px; font-weight: 600; color: ' . $accent . ';
    }
    {s}.dp-ml-summary::-webkit-details-marker { display: none; }
    {s}.dp-ml-summary::after {
        content: ""; margin-left: auto; width: 7px; height: 7px;
        border-right: 2px solid currentColor; border-bottom: 2px solid currentColor;
        transform: rotate(45deg) translateY(-2px); transition: transform .2s;
    }
    {s}.dp-ml-box[open] > .dp-ml-summary { padding-bottom: 12px; }
    {s}.dp-ml-box[open] > .dp-ml-summary::after { transform: rotate(-135deg) translateY(-2px); }
    {s}.dp-ml-summary:focus-visible { outline: 2px solid ' . $accent . '; outline-offset: -2px; }
    {s}.dp-ml-panel { padding: 0 22px 22px; }
    {s}.dp-ml-box--plain .dp-ml-panel { padding-top: 22px; }
    {s}.dp-ml-title { margin: 0 0 6px; font-size: 15px; font-weight: 600; color: #1d2327; }
    {s}.dp-ml-help { margin: 0 0 16px; font-size: 13px; line-height: 1.5; color: #646970; }
    {s}.dp-ml-srlabel { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    {s}.dp-ml-input {
        width: 100%; box-sizing: border-box; padding: 10px 12px; font-size: 15px;
        border: 1px solid #c3c4c7; border-radius: 6px; margin-bottom: 12px;
        background: #fff; color: #1d2327; line-height: 1.4;
    }
    {s}.dp-ml-input:focus { outline: none; border-color: ' . $accent . '; box-shadow: 0 0 0 2px rgba(0,0,0,0.10); }
    {s}.dp-ml-btn {
        display: block; width: 100%; padding: 13px 16px; margin-top: 6px; font-size: 14px; font-weight: 600;
        color: #fff; background: ' . $accent . '; border: none; border-radius: 6px; cursor: pointer;
        transition: background .2s; line-height: 1.4;
    }
    {s}.dp-ml-btn:hover { background: ' . $hover . '; }
    {s}.dp-ml-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    {s}.dp-ml-notice { margin: 0 0 16px; padding: 12px 16px 12px 19px; border-radius: 6px; font-size: 13px; line-height: 1.55; }
    {s}.dp-ml-notice--ok { background: #f1f6f2; border-left: 3px solid #00a32a; color: #1d2327; }
    {s}.dp-ml-notice--warn { background: #fcf9e8; border-left: 3px solid #dba617; color: #1d2327; }
    {s}.dp-ml-lead { margin: 0 0 16px; font-size: 16px; color: #1d2327; }

    /* --- cijfercode --- */
    {s}.dp-ml-label { display: block; margin: 0 0 6px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #646970; }
    {s}.dp-ml-input--code {
        font-size: 22px; font-weight: 600; letter-spacing: .32em; text-align: center;
        padding: 12px 12px; font-variant-numeric: tabular-nums;
    }
    {s}.dp-ml-input--code::placeholder { letter-spacing: .32em; color: #c3c4c7; font-weight: 400; }
    {s}.dp-ml-form--code { margin: 0 0 4px; }
    {s}.dp-ml-scheiding {
        display: flex; align-items: center; gap: 10px;
        margin: 18px 0 16px; font-size: 12px; color: #8c8f94;
    }
    {s}.dp-ml-scheiding::before, {s}.dp-ml-scheiding::after {
        content: ""; flex: 1; height: 1px; background: #dcdcde;
    }
    ';

    return str_replace( '{s}', $scope, $css );
}

/* ---- Shortcode ---- */

add_shortcode( 'dp_magic_login', function ( $atts ) {
    if ( is_user_logged_in() ) {
        return '';
    }

    $atts        = shortcode_atts( [ 'redirect' => '', 'inklapbaar' => 'nee' ], $atts, 'dp_magic_login' );
    $collapsible = in_array( strtolower( (string) $atts['inklapbaar'] ), [ 'ja', 'yes', '1', 'true' ], true );

    return '<style>' . dp_toolbox_ml_styles() . '</style>'
        . dp_toolbox_ml_form_html( $atts['redirect'], $collapsible );
} );

/* ---- Blok op de wp-login pagina ---- */

add_action( 'login_enqueue_scripts', function () {
    if ( ! dp_toolbox_ml_setting( 'show_on_login' ) ) {
        return;
    }

    $accent = function_exists( 'dp_toolbox_branding_color' ) ? dp_toolbox_branding_color( 'accent' ) : '#281E5D';

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<style>' . dp_toolbox_ml_styles( '#login ' ) . '
    #login .dp-ml { margin: 20px 0 0; margin-left: 8px; }
    #login .dp-ml-box { box-shadow: 0 8px 32px rgba(0,0,0,0.3); }

    /* WordPress en de Login Branding-module stylen .login form als een eigen
       witte kaart met padding. Dat treft ook ons formulier, wat een kaart in
       een kaart oplevert. Hier zetten we dat terug. */
    #login .dp-ml-form {
        background: none !important; border: none !important; box-shadow: none !important;
        padding: 0 !important; margin: 0 !important; overflow: visible !important;
    }
    #login .dp-ml-form p { margin: 0; padding: 0; }

    /* Schakelaar boven het formulier */
    #login .dp-ml-tabs {
        display: flex; gap: 4px; padding: 4px; margin: 0 0 14px;
        background: rgba(255,255,255,0.12); border-radius: 10px;
    }
    #login .dp-ml-tab {
        flex: 1; padding: 9px 10px; border: none; border-radius: 7px; cursor: pointer;
        font-size: 13px; font-weight: 600; font-family: inherit; line-height: 1.4;
        color: rgba(255,255,255,0.75); background: none; transition: background .15s, color .15s;
    }
    #login .dp-ml-tab:hover { color: #fff; }
    #login .dp-ml-tab.is-active { background: #fff; color: ' . $accent . '; }
    #login .dp-ml-tab:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
    #login .dp-ml-pane .dp-ml-panel { padding: 22px; }
    </style>';
} );

add_action( 'login_footer', function () {
    if ( ! dp_toolbox_ml_setting( 'show_on_login' ) ) {
        return;
    }

    $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
    $html     = dp_toolbox_ml_form_html( $redirect, true );
    $geopend  = ! empty( $_GET['dp-ml'] );
    ?>
    <div id="dp-ml-login-block" hidden><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <script>
    (function () {
        var block = document.getElementById('dp-ml-login-block');
        var form  = document.getElementById('loginform');
        if (!block || !form || !form.parentNode) { return; }

        var details = block.querySelector('.dp-ml-box');
        var panel   = block.querySelector('.dp-ml-panel');

        /* Zonder JS blijft het een uitklapbaar blok onder het formulier. Met JS
           maken we er een schakelaar van: wachtwoord of inloglink, één van beide. */
        if (!details || !panel) {
            form.parentNode.insertBefore(block, form.nextSibling);
            block.hidden = false;
            return;
        }

        var pane = document.createElement('div');
        pane.className = 'dp-ml dp-ml-pane';
        var box = document.createElement('div');
        box.className = 'dp-ml-box';
        box.appendChild(panel);
        pane.appendChild(box);

        var tabs = document.createElement('div');
        tabs.className = 'dp-ml-tabs';
        tabs.setAttribute('role', 'tablist');

        function maakTab(label, actief) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'dp-ml-tab' + (actief ? ' is-active' : '');
            b.textContent = label;
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', actief ? 'true' : 'false');
            tabs.appendChild(b);
            return b;
        }

        var startOpLink = <?php echo $geopend ? 'true' : 'false'; ?>;
        var tabWachtwoord = maakTab('Wachtwoord', !startOpLink);
        var tabLink       = maakTab('Inloglink', startOpLink);

        form.parentNode.insertBefore(tabs, form);
        form.parentNode.insertBefore(pane, form.nextSibling);
        block.parentNode.removeChild(block);

        // Uitlijnen met het inlogformulier, dat een eigen marge van WordPress krijgt.
        var marge = window.getComputedStyle(form).marginLeft;
        tabs.style.marginLeft = marge;
        pane.style.marginLeft = marge;
        pane.style.marginTop  = '0';

        function toon(link) {
            form.style.display = link ? 'none' : '';
            pane.style.display = link ? '' : 'none';
            tabLink.classList.toggle('is-active', link);
            tabWachtwoord.classList.toggle('is-active', !link);
            tabLink.setAttribute('aria-selected', link ? 'true' : 'false');
            tabWachtwoord.setAttribute('aria-selected', link ? 'false' : 'true');
            var veld = link ? pane.querySelector('.dp-ml-input') : document.getElementById('user_login');
            if (veld) { try { veld.focus(); } catch (e) {} }
        }

        tabWachtwoord.addEventListener('click', function () { toon(false); });
        tabLink.addEventListener('click', function () { toon(true); });

        toon(startOpLink);
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
