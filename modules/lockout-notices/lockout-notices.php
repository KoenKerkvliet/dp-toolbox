<?php
/**
 * Module Name: Uitsluitingsmeldingen
 * Description: Filtert de uitsluitingsmails van AIOS: alleen nog bericht als het om een bestaand account gaat, niet bij bots.
 * Category: security
 * Requires: aios
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Hoe dit werkt
 * -------------
 * AIOS schrijft bij elke uitsluiting een regel in zijn eigen tabel met een vlag
 * `is_lockout_email_sent`: 0 = moet nog gemaild, -1 = niet mailen, 1 = verstuurd.
 * Een losse cron pikt later alles met 0 op en stuurt één mail.
 *
 * In diezelfde regel bepaalt AIOS ook `user_id`, en dat doet het zo:
 *
 *     $user = is_email($username) ? get_user_by('email', $username)
 *                                 : get_user_by('login', $username);
 *
 * Dus `user_id = 0` betekent: dit account bestaat niet — een bot. Een lid dat
 * met zijn e-mailadres inlogt wordt wél herkend en houdt dus zijn melding.
 *
 * Wij zetten de vlag van die bot-regels op -1: exact dezelfde waarde die AIOS
 * zelf gebruikt als je meldingen uitzet, alleen per melding in plaats van
 * globaal. Geen omweg om de plugin heen, maar zijn eigen schakelaar.
 */

const DP_TOOLBOX_LN_OPTIE  = 'dp_toolbox_lockout_notices';
const DP_TOOLBOX_LN_STATS  = 'dp_toolbox_lockout_notices_stats';

/* ================================================================== */
/*  Instellingen                                                       */
/* ================================================================== */

function dp_toolbox_ln_defaults() {
    return [
        'filteren'    => 1, // bot-meldingen onderdrukken
        'log_naar_al' => 0, // onderdrukte uitsluitingen ook in de Activity Log
    ];
}

function dp_toolbox_ln_instellingen() {
    $opgeslagen = get_option( DP_TOOLBOX_LN_OPTIE, [] );

    return array_merge( dp_toolbox_ln_defaults(), is_array( $opgeslagen ) ? $opgeslagen : [] );
}

function dp_toolbox_ln_actief() {
    $s = dp_toolbox_ln_instellingen();

    return ! empty( $s['filteren'] );
}

/* ================================================================== */
/*  Teller                                                             */
/* ================================================================== */

/**
 * Telt onderdrukte meldingen. De maandteller begint elke maand opnieuw; het
 * totaal loopt door, zodat je ziet dat het werkt zonder in je mail te kijken.
 *
 * @param int    $aantal Aantal regels dat we zojuist hebben onderdrukt.
 * @param string $maand  Huidige maand als Y-m (injecteerbaar voor de test).
 */
function dp_toolbox_ln_tel_op( $aantal, $maand = null ) {
    $aantal = max( 0, (int) $aantal );
    if ( ! $aantal ) {
        return dp_toolbox_ln_stats();
    }

    $maand = $maand ? $maand : wp_date( 'Y-m' );
    $stats = dp_toolbox_ln_stats();

    if ( ( $stats['maand'] ?? '' ) !== $maand ) {
        $stats['maand']       = $maand;
        $stats['deze_maand']  = 0;
    }

    $stats['deze_maand'] = (int) $stats['deze_maand'] + $aantal;
    $stats['totaal']     = (int) $stats['totaal'] + $aantal;
    $stats['laatste']    = time();

    update_option( DP_TOOLBOX_LN_STATS, $stats, false );

    return $stats;
}

function dp_toolbox_ln_stats() {
    $stats = get_option( DP_TOOLBOX_LN_STATS, [] );
    if ( ! is_array( $stats ) ) {
        $stats = [];
    }

    return array_merge( [
        'maand'      => '',
        'deze_maand' => 0,
        'totaal'     => 0,
        'laatste'    => 0,
    ], $stats );
}

/* ================================================================== */
/*  Het filter zelf                                                    */
/* ================================================================== */

/**
 * Bestaat de vlag-kolom in deze AIOS-versie?
 *
 * Zo niet, dan doen we niets. De mail blijft dan gewoon komen — vervelend maar
 * ongevaarlijk, en dat is de goede kant om op te falen.
 */
function dp_toolbox_ln_kolom_aanwezig() {
    static $bekend = null;

    if ( null !== $bekend ) {
        return $bekend;
    }

    if ( ! defined( 'AIOWPSEC_TBL_LOGIN_LOCKOUT' ) ) {
        return $bekend = false;
    }

    global $wpdb;
    $tabel = AIOWPSEC_TBL_LOGIN_LOCKOUT;

    $kolommen = $wpdb->get_col( "SHOW COLUMNS FROM `{$tabel}`" );
    if ( ! is_array( $kolommen ) ) {
        return $bekend = false;
    }

    return $bekend = ( in_array( 'is_lockout_email_sent', $kolommen, true )
        && in_array( 'user_id', $kolommen, true ) );
}

/**
 * AIOS vuurt dit direct nadat de uitsluiting is weggeschreven, en ruim vóór de
 * cron die de mail verstuurt.
 */
add_action( 'aiowps_lockdown_event', function ( $ip_range = '', $username = '' ) {
    if ( ! dp_toolbox_ln_actief() || ! dp_toolbox_ln_kolom_aanwezig() ) {
        return;
    }

    global $wpdb;
    $tabel = AIOWPSEC_TBL_LOGIN_LOCKOUT;

    // Alles wat nog gemaild moet worden én geen bestaand account betreft.
    $onderdrukt = $wpdb->query(
        "UPDATE `{$tabel}` SET is_lockout_email_sent = -1 WHERE is_lockout_email_sent = 0 AND user_id = 0"
    );

    if ( ! $onderdrukt ) {
        return;
    }

    dp_toolbox_ln_tel_op( $onderdrukt );

    $s = dp_toolbox_ln_instellingen();
    if ( ! empty( $s['log_naar_al'] ) && function_exists( 'dp_toolbox_al_log' ) ) {
        dp_toolbox_al_log( 'login', 'Uitsluitingsmelding onderdrukt', [
            'object_name' => (string) $username,
            'details'     => 'Onbekend account, IP-bereik ' . (string) $ip_range,
        ] );
    }
}, 10, 2 );

/* ================================================================== */
/*  Hulpjes voor het instellingenpaneel                                */
/* ================================================================== */

/**
 * Stuurt AIOS überhaupt meldingen? Zo niet, dan doet deze module niets zinnigs
 * en zeggen we dat er eerlijk bij.
 */
function dp_toolbox_ln_aios_mailt() {
    $configs = get_option( 'aio_wp_security_configs' );

    return is_array( $configs ) && '1' === (string) ( $configs['aiowps_enable_email_notify'] ?? '' );
}

function dp_toolbox_ln_aios_ontvangers() {
    $configs = get_option( 'aio_wp_security_configs' );
    $adres   = $configs['aiowps_email_address'] ?? '';

    if ( is_array( $adres ) ) {
        return implode( ', ', $adres );
    }

    $adres = trim( (string) $adres );

    return $adres ? $adres : (string) get_option( 'admin_email' );
}

/**
 * Hoeveel uitsluitingen staan er op dit moment in de wacht, en van wie?
 */
function dp_toolbox_ln_wachtrij() {
    if ( ! dp_toolbox_ln_kolom_aanwezig() ) {
        return [ 'bots' => 0, 'echte' => 0 ];
    }

    global $wpdb;
    $tabel = AIOWPSEC_TBL_LOGIN_LOCKOUT;

    return [
        'bots'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tabel}` WHERE is_lockout_email_sent = -1 AND user_id = 0" ),
        'echte' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tabel}` WHERE user_id > 0" ),
    ];
}

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
