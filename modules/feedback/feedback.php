<?php
/**
 * Module Name: Feedback
 * Description: Laat klanten op de website zelf feedback achterlaten, met een pin op het element waar het om gaat. Jij handelt alles af vanuit één overzicht.
 * Category: content
 * Version: 1.0.0
 *
 * Gebaseerd op de opmerkingenfunctie van Pencilino by Rino de Boer
 * (https://github.com/rinothecoder/pencilino), uitgebracht onder GPL-2.0-or-later.
 * Vertaald naar het Nederlands en ingepast in DP Toolbox: eigen tabel, Design
 * Pixels-branding, een instelbare rol voor wie feedback mag geven, afhandelen
 * vanaf de site zelf en een eenmalige overname van bestaande Pencilino-opmerkingen.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const DP_TOOLBOX_FB_VERSIE = '1.0.0';

/* ================================================================== */
/*  Opslag                                                             */
/* ================================================================== */

function dp_toolbox_fb_table() {
    global $wpdb;
    return $wpdb->prefix . 'dp_feedback';
}

/**
 * Tabel aanmaken op `init`, niet op `admin_init`: de eerste feedback kan via
 * de REST-route binnenkomen voordat iemand wp-admin heeft geopend.
 */
function dp_toolbox_fb_ensure_table() {
    global $wpdb;

    if ( get_option( 'dp_toolbox_fb_table_version' ) === '1.0' ) {
        return;
    }

    $table   = dp_toolbox_fb_table();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        page_url text NOT NULL,
        page_key char(64) NOT NULL,
        page_title varchar(191) NOT NULL DEFAULT '',
        page_id bigint(20) unsigned NOT NULL DEFAULT 0,
        target longtext NOT NULL,
        comment_text text NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_name varchar(191) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        resolved_at datetime DEFAULT NULL,
        resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY page_key (page_key),
        KEY created_at (created_at),
        KEY resolved_at (resolved_at)
    ) {$charset};" );

    if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        update_option( 'dp_toolbox_fb_table_version', '1.0' );
    }
}
add_action( 'init', 'dp_toolbox_fb_ensure_table', 5 );

/**
 * Eenmalig de opmerkingen uit Pencilino overnemen, zodat een site die daarmee
 * begon niets kwijtraakt. De Pencilino-tabel zelf blijft onaangeroerd.
 */
function dp_toolbox_fb_migreer_pencilino() {
    global $wpdb;

    if ( get_option( 'dp_toolbox_fb_pencilino_overgenomen' ) || get_option( 'dp_toolbox_fb_table_version' ) !== '1.0' ) {
        return;
    }

    $bron = $wpdb->prefix . 'pencil_comments';
    if ( $bron !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bron ) ) ) {
        update_option( 'dp_toolbox_fb_pencilino_overgenomen', [ 'aantal' => 0, 'op' => time() ], false );
        return;
    }

    $kolommen = 'page_url, page_key, page_title, page_id, target, comment_text, user_id, user_name, created_at, resolved_at, resolved_by';
    $aantal   = $wpdb->query( "INSERT INTO " . dp_toolbox_fb_table() . " ({$kolommen}) SELECT {$kolommen} FROM {$bron} ORDER BY comment_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL -- tabelnamen uit $wpdb->prefix.

    update_option( 'dp_toolbox_fb_pencilino_overgenomen', [ 'aantal' => (int) $aantal, 'op' => time() ], false );
}
add_action( 'admin_init', 'dp_toolbox_fb_migreer_pencilino' );

/* ================================================================== */
/*  Rol "Klant" (staat in includes/, ook bruikbaar voor andere modules) */
/* ================================================================== */

require_once DP_TOOLBOX_PATH . 'includes/klant-rol.php';

function dp_toolbox_fb_is_klant( $user = null ) {
    return dp_toolbox_klant_is( $user );
}

/* ================================================================== */
/*  Rechten                                                            */
/* ================================================================== */

/**
 * Rollen die feedback mogen geven. Beheerders mogen het altijd.
 */
function dp_toolbox_fb_rollen() {
    $standaard = [ 'administrator', 'editor', DP_TOOLBOX_KLANT_ROL ];
    $rollen    = get_option( 'dp_toolbox_fb_rollen', $standaard );
    return is_array( $rollen ) ? array_values( array_filter( array_map( 'sanitize_key', $rollen ) ) ) : $standaard;
}

function dp_toolbox_fb_mag_geven( $user = null ) {
    $user = $user instanceof WP_User ? $user : wp_get_current_user();
    if ( ! $user || ! $user->exists() ) {
        return false;
    }

    $mag = user_can( $user, 'manage_options' ) || (bool) array_intersect( (array) $user->roles, dp_toolbox_fb_rollen() );

    /**
     * Eigen regel per site, bijvoorbeeld één specifieke klantaccount.
     */
    return (bool) apply_filters( 'dp_toolbox_feedback_mag_geven', $mag, $user );
}

/**
 * Afvinken, heropenen en verwijderen: alleen beheerders.
 */
function dp_toolbox_fb_mag_afhandelen() {
    return (bool) apply_filters( 'dp_toolbox_feedback_mag_afhandelen', current_user_can( 'manage_options' ) );
}

/**
 * Het overzicht staat in het DP Toolbox-paneel, en dat is alleen voor Design Pixels zichtbaar.
 */
function dp_toolbox_fb_overzicht_url() {
    if ( ! function_exists( 'dp_toolbox_current_user_has_access' ) || ! dp_toolbox_current_user_has_access() ) {
        return '';
    }
    return admin_url( 'admin.php?page=dp-feedback' );
}

/* ================================================================== */
/*  Gegevens                                                           */
/* ================================================================== */

/**
 * Een vaste, eigen pagina-URL: nooit tokens of willekeurige adressen opslaan.
 * Overgenomen uit Pencilino (Pencil_Comments::page_url).
 */
function dp_toolbox_fb_pagina_url( $url ) {
    $url   = esc_url_raw( $url );
    $parts = wp_parse_url( $url );
    $home  = wp_parse_url( home_url( '/' ) );

    if ( ! $parts || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] )
        || ! empty( $parts['user'] ) || ! empty( $parts['pass'] )
        || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true )
        || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
        return new WP_Error( 'dp_fb_pagina', 'Kies een pagina van deze website.', [ 'status' => 400 ] );
    }

    $path      = $parts['path'] ?? '/';
    $home_path = trailingslashit( $home['path'] ?? '/' );
    if ( 0 !== strpos( trailingslashit( $path ), $home_path ) || preg_match( '~/(?:wp-admin|wp-login\.php|wp-json)(?:/|$)~', $path ) ) {
        return new WP_Error( 'dp_fb_pagina', 'Feedback hoort bij een pagina van de website zelf.', [ 'status' => 400 ] );
    }

    $canonical = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path;

    // Alleen de queryparameters die bepalen wélke pagina het is; geen tracking of tokens.
    $query = [];
    if ( ! empty( $parts['query'] ) ) {
        parse_str( $parts['query'], $query );
    }
    $query = array_intersect_key( $query, array_flip( [ 'p', 'page_id', 'post_type', 'name', 'pagename', 'paged', 'page', 's', 'cat', 'tag' ] ) );
    $query = array_filter( $query, 'is_scalar' );
    ksort( $query );

    return add_query_arg( $query, $canonical );
}

function dp_toolbox_fb_naar_array( $rij ) {
    $doel = json_decode( (string) $rij->target, true );
    $doel = is_array( $doel ) ? $doel : [];
    $tijd = strtotime( $rij->created_at . ' UTC' );

    // Overgenomen uit Pencilino: daar heten deze sleutels nog 'field' en 'text'.
    foreach ( [ 'field' => 'veld', 'text' => 'tekst' ] as $oud => $nieuw ) {
        if ( ! isset( $doel[ $nieuw ] ) && isset( $doel[ $oud ] ) ) {
            $doel[ $nieuw ] = $doel[ $oud ];
        }
        unset( $doel[ $oud ] );
    }

    return [
        'id'          => (int) $rij->id,
        'pagina'      => $rij->page_url,
        'paginatitel' => $rij->page_title,
        'pagina_id'   => (int) $rij->page_id,
        'doel'        => $doel,
        'tekst'       => $rij->comment_text,
        'auteur'      => $rij->user_name,
        'datum'       => $tijd ? wp_date( 'j F Y, H:i', $tijd ) : '',
        'afgehandeld' => ! empty( $rij->resolved_at ),
    ];
}

function dp_toolbox_fb_lijst( $afgehandeld = false, $aantal = 50, $vanaf = 0 ) {
    global $wpdb;

    $waar = $afgehandeld ? 'resolved_at IS NOT NULL' : 'resolved_at IS NULL';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM %i WHERE {$waar} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL -- $waar is een vaste string.
        dp_toolbox_fb_table(),
        max( 1, (int) $aantal ),
        max( 0, (int) $vanaf )
    ) );
}

function dp_toolbox_fb_aantallen() {
    global $wpdb;

    $rij = $wpdb->get_row( $wpdb->prepare(
        'SELECT SUM(resolved_at IS NULL) AS open, SUM(resolved_at IS NOT NULL) AS afgehandeld FROM %i',
        dp_toolbox_fb_table()
    ) );

    return [
        'open'        => (int) ( $rij->open ?? 0 ),
        'afgehandeld' => (int) ( $rij->afgehandeld ?? 0 ),
    ];
}

function dp_toolbox_fb_zet_status( $id, $afgehandeld ) {
    global $wpdb;

    $id = absint( $id );
    if ( ! $id || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', dp_toolbox_fb_table(), $id ) ) ) {
        return new WP_Error( 'dp_fb_onbekend', 'Deze feedback bestaat niet (meer).', [ 'status' => 404 ] );
    }

    $resultaat = $wpdb->update(
        dp_toolbox_fb_table(),
        [
            'resolved_at' => $afgehandeld ? current_time( 'mysql', true ) : null,
            'resolved_by' => $afgehandeld ? get_current_user_id() : 0,
        ],
        [ 'id' => $id ],
        [ '%s', '%d' ],
        [ '%d' ]
    );

    if ( false === $resultaat ) {
        return new WP_Error( 'dp_fb_opslaan', 'De status kon niet worden opgeslagen.', [ 'status' => 500 ] );
    }

    dp_toolbox_fb_log( $afgehandeld ? 'Feedback afgehandeld' : 'Feedback heropend', $id );
    return true;
}

function dp_toolbox_fb_verwijder( $id ) {
    global $wpdb;

    $id = absint( $id );
    $ok = $id && $wpdb->delete( dp_toolbox_fb_table(), [ 'id' => $id ], [ '%d' ] );
    if ( $ok ) {
        dp_toolbox_fb_log( 'Feedback verwijderd', $id );
    }
    return (bool) $ok;
}

/**
 * Meeschrijven in de Activity Log-module, als die aan staat.
 */
function dp_toolbox_fb_log( $actie, $id, $details = '' ) {
    if ( function_exists( 'dp_toolbox_al_log' ) ) {
        dp_toolbox_al_log( 'content', $actie, [
            'object_type' => 'feedback',
            'object_id'   => (int) $id,
            'object_name' => 'Feedback #' . (int) $id,
            'details'     => $details,
        ] );
    }
}

/* ================================================================== */
/*  REST                                                               */
/* ================================================================== */

add_action( 'rest_api_init', function () {
    register_rest_route( 'dp-toolbox/v1', '/feedback', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'dp_toolbox_fb_rest_pagina',
            'permission_callback' => 'dp_toolbox_fb_mag_geven',
            'args'                => [ 'pagina' => [ 'required' => true, 'type' => 'string' ] ],
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'dp_toolbox_fb_rest_nieuw',
            'permission_callback' => 'dp_toolbox_fb_mag_geven',
            'args'                => [
                'pagina'      => [ 'required' => true, 'type' => 'string' ],
                'tekst'       => [ 'required' => true, 'type' => 'string', 'maxLength' => 5000 ],
                'doel'        => [ 'required' => true, 'type' => 'object' ],
                'paginatitel' => [ 'type' => 'string', 'maxLength' => 191 ],
                'pagina_id'   => [ 'type' => 'integer', 'minimum' => 0 ],
            ],
        ],
    ] );

    register_rest_route( 'dp-toolbox/v1', '/feedback/(?P<id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'dp_toolbox_fb_rest_een',
            'permission_callback' => 'dp_toolbox_fb_mag_geven',
        ],
        [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => 'dp_toolbox_fb_rest_status',
            'permission_callback' => 'dp_toolbox_fb_mag_afhandelen',
            'args'                => [ 'afgehandeld' => [ 'required' => true, 'type' => 'boolean' ] ],
        ],
    ] );
} );

function dp_toolbox_fb_rest_pagina( WP_REST_Request $request ) {
    global $wpdb;

    $url = dp_toolbox_fb_pagina_url( $request['pagina'] );
    if ( is_wp_error( $url ) ) {
        return $url;
    }

    $rijen = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM %i WHERE page_key = %s AND resolved_at IS NULL ORDER BY id ASC',
        dp_toolbox_fb_table(),
        hash( 'sha256', $url )
    ) );

    return rest_ensure_response( array_map( 'dp_toolbox_fb_naar_array', $rijen ) );
}

function dp_toolbox_fb_rest_een( WP_REST_Request $request ) {
    global $wpdb;

    $rij = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', dp_toolbox_fb_table(), absint( $request['id'] ) ) );

    return $rij
        ? rest_ensure_response( dp_toolbox_fb_naar_array( $rij ) )
        : new WP_Error( 'dp_fb_onbekend', 'Deze feedback bestaat niet (meer).', [ 'status' => 404 ] );
}

function dp_toolbox_fb_rest_nieuw( WP_REST_Request $request ) {
    global $wpdb;

    $url = dp_toolbox_fb_pagina_url( $request['pagina'] );
    if ( is_wp_error( $url ) ) {
        return $url;
    }

    $tekst = sanitize_textarea_field( $request['tekst'] );
    if ( '' === trim( $tekst ) || mb_strlen( $tekst ) > 5000 ) {
        return new WP_Error( 'dp_fb_tekst', 'Schrijf een opmerking van maximaal 5.000 tekens.', [ 'status' => 400 ] );
    }

    $ruw  = (array) $request['doel'];
    $doel = [];
    foreach ( [ 'selector' => 2000, 'veld' => 191, 'id' => 191, 'tag' => 30, 'label' => 191, 'tekst' => 240 ] as $sleutel => $max ) {
        $waarde           = isset( $ruw[ $sleutel ] ) && is_scalar( $ruw[ $sleutel ] ) ? sanitize_text_field( (string) $ruw[ $sleutel ] ) : '';
        $doel[ $sleutel ] = mb_substr( $waarde, 0, $max );
    }
    if ( '' === $doel['selector'] && '' === $doel['veld'] && '' === $doel['id'] ) {
        return new WP_Error( 'dp_fb_doel', 'Kies eerst het element waar je feedback over hebt.', [ 'status' => 400 ] );
    }
    foreach ( [ 'x', 'y' ] as $as ) {
        $doel[ $as ] = isset( $ruw[ $as ] ) && is_numeric( $ruw[ $as ] ) ? min( 1, max( 0, (float) $ruw[ $as ] ) ) : 0.5;
    }

    $user = wp_get_current_user();
    $rij  = [
        'page_url'     => $url,
        'page_key'     => hash( 'sha256', $url ),
        'page_title'   => mb_substr( sanitize_text_field( (string) ( $request['paginatitel'] ?? '' ) ), 0, 191 ),
        'page_id'      => absint( $request['pagina_id'] ?? 0 ),
        'target'       => wp_json_encode( $doel ),
        'comment_text' => $tekst,
        'user_id'      => $user->ID,
        'user_name'    => $user->display_name,
        'created_at'   => current_time( 'mysql', true ),
    ];

    if ( false === $wpdb->insert( dp_toolbox_fb_table(), $rij, [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ] ) ) {
        return new WP_Error( 'dp_fb_opslaan', 'De feedback kon niet worden opgeslagen. Probeer het opnieuw.', [ 'status' => 500 ] );
    }

    $id = (int) $wpdb->insert_id;
    dp_toolbox_fb_log( 'Feedback geplaatst', $id, $rij['page_title'] );

    /**
     * Na het opslaan, bijvoorbeeld voor een melding per mail of chat.
     */
    do_action( 'dp_toolbox_feedback_geplaatst', $id, $rij );

    $rij['id']          = $id;
    $rij['resolved_at'] = null;

    return new WP_REST_Response( dp_toolbox_fb_naar_array( (object) $rij ), 201 );
}

function dp_toolbox_fb_rest_status( WP_REST_Request $request ) {
    $resultaat = dp_toolbox_fb_zet_status( $request['id'], (bool) $request['afgehandeld'] );

    return is_wp_error( $resultaat )
        ? $resultaat
        : rest_ensure_response( [ 'id' => absint( $request['id'] ), 'afgehandeld' => (bool) $request['afgehandeld'] ] );
}

/* ================================================================== */
/*  Op de website                                                      */
/* ================================================================== */

/**
 * Niet in de paginabouwers zelf: daar zou een klik op een element geen feedback moeten openen.
 */
function dp_toolbox_fb_tonen() {
    if ( is_admin() || is_customize_preview() || ! dp_toolbox_fb_mag_geven() ) {
        return false;
    }
    foreach ( [ 'bricks_is_builder', 'bricks_is_builder_iframe', 'bricks_is_builder_main' ] as $functie ) {
        if ( function_exists( $functie ) && $functie() ) {
            return false;
        }
    }
    if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['et_fb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- alleen lezen.
        return false;
    }

    return (bool) apply_filters( 'dp_toolbox_feedback_tonen', true );
}

add_action( 'wp_enqueue_scripts', function () {
    if ( ! dp_toolbox_fb_tonen() ) {
        return;
    }

    $map = DP_TOOLBOX_PATH . 'modules/feedback/';
    $url = DP_TOOLBOX_URL . 'modules/feedback/';

    wp_enqueue_style( 'dp-toolbox-feedback', $url . 'feedback.css', [], (string) filemtime( $map . 'feedback.css' ) );
    wp_add_inline_style( 'dp-toolbox-feedback', sprintf(
        '.dp-fb-ui{--dp-fb-accent:%1$s;--dp-fb-accent-hover:%2$s;--dp-fb-accent-rgb:%3$s;}',
        esc_attr( function_exists( 'dp_toolbox_branding_color' ) ? dp_toolbox_branding_color( 'accent' ) : '#281E5D' ),
        esc_attr( function_exists( 'dp_toolbox_branding_color' ) ? dp_toolbox_branding_color( 'accent_hover' ) : '#4a3a8a' ),
        esc_attr( function_exists( 'dp_toolbox_branding_rgb' ) ? dp_toolbox_branding_rgb( 'accent' ) : '40, 30, 93' )
    ) );

    wp_enqueue_script( 'dp-toolbox-feedback', $url . 'feedback.js', [], (string) filemtime( $map . 'feedback.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
    wp_localize_script( 'dp-toolbox-feedback', 'dpFeedback', [
        'rest'          => esc_url_raw( rest_url( 'dp-toolbox/v1/feedback' ) ),
        'nonce'         => wp_create_nonce( 'wp_rest' ),
        'paginaId'      => get_queried_object_id(),
        'magAfhandelen' => dp_toolbox_fb_mag_afhandelen(),
        'overzicht'     => dp_toolbox_fb_overzicht_url(),
        // Een klant heeft geen adminbalk, dus uitloggen moet in de feedbackbalk zelf kunnen.
        'uitloggen'     => dp_toolbox_klant_uitlog_url(),
    ] );
} );

/**
 * Teller in de adminbalk voor wie het overzicht kan openen.
 */
add_action( 'admin_bar_menu', function ( $bar ) {
    $overzicht = dp_toolbox_fb_overzicht_url();
    if ( ! $overzicht || get_option( 'dp_toolbox_fb_table_version' ) !== '1.0' ) {
        return;
    }

    $open = dp_toolbox_fb_aantallen()['open'];
    $bar->add_node( [
        'id'    => 'dp-feedback',
        'title' => $open ? sprintf( 'Feedback <span class="count" style="display:inline-block;min-width:18px;padding:0 5px;margin-left:4px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;">%d</span>', $open ) : 'Feedback',
        'href'  => $overzicht,
        'meta'  => [ 'title' => $open ? $open . ' open' : 'Geen open feedback' ],
    ] );
}, 85 );

/* ================================================================== */
/*  Beheer (AJAX vanuit het overzicht)                                 */
/* ================================================================== */

function dp_toolbox_fb_ajax_check() {
    check_ajax_referer( 'dp_toolbox_fb', 'nonce' );
    if ( ! dp_toolbox_fb_mag_afhandelen() ) {
        wp_send_json_error( 'Geen toegang.', 403 );
    }
}

add_action( 'wp_ajax_dp_toolbox_fb_status', function () {
    dp_toolbox_fb_ajax_check();
    $resultaat = dp_toolbox_fb_zet_status( absint( $_POST['id'] ?? 0 ), ! empty( $_POST['afgehandeld'] ) );
    is_wp_error( $resultaat ) ? wp_send_json_error( $resultaat->get_error_message() ) : wp_send_json_success();
} );

add_action( 'wp_ajax_dp_toolbox_fb_verwijderen', function () {
    dp_toolbox_fb_ajax_check();
    dp_toolbox_fb_verwijder( absint( $_POST['id'] ?? 0 ) ) ? wp_send_json_success() : wp_send_json_error( 'Verwijderen mislukt.' );
} );

add_action( 'wp_ajax_dp_toolbox_fb_rollen', function () {
    check_ajax_referer( 'dp_toolbox_fb', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toegang.', 403 );
    }

    $bestaand = array_keys( wp_roles()->roles );
    $rollen   = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['rollen'] ?? [] ) ), $bestaand ) );
    update_option( 'dp_toolbox_fb_rollen', $rollen, false );
    wp_send_json_success( $rollen );
} );

/* ================================================================== */
/*  Abilities (MCP): feedback uitlezen en afhandelen                   */
/* ================================================================== */

add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }

    $meta = function ( $alleen_lezen ) {
        return [
            'show_in_rest' => true,
            'mcp'          => [ 'public' => true ],
            'annotations'  => [ 'readonly' => $alleen_lezen, 'destructive' => false, 'idempotent' => true ],
        ];
    };

    wp_register_ability( 'dp-toolbox/feedback-lijst', [
        'label'               => 'Feedback: lijst',
        'description'         => 'Geeft de feedback die mensen op de website hebben achtergelaten, 50 per pagina, nieuwste eerst. Elk item bevat de pagina, de tekst, de auteur, de datum en het doel: CSS-selector, Pencilino-veld-ID, element-ID, tag, label, een tekstfragment en de positie van de pin (x en y van 0 tot 1). Gebruik het doel om het element in het thema te vinden. Alleen voor beheerders.',
        'category'            => 'site',
        'input_schema'        => [
            'type'                 => 'object',
            'properties'           => [
                'status' => [ 'type' => 'string', 'enum' => [ 'open', 'afgehandeld' ], 'default' => 'open' ],
                'pagina' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
            ],
            'additionalProperties' => false,
            'default'              => [],
        ],
        'output_schema'       => [ 'type' => 'object' ],
        'execute_callback'    => function ( $input ) {
            $afgehandeld = isset( $input['status'] ) && 'afgehandeld' === $input['status'];
            $pagina      = max( 1, (int) ( $input['pagina'] ?? 1 ) );
            $rijen       = dp_toolbox_fb_lijst( $afgehandeld, 51, ( $pagina - 1 ) * 50 );
            return [
                'status'  => $afgehandeld ? 'afgehandeld' : 'open',
                'pagina'  => $pagina,
                'meer'    => count( $rijen ) > 50,
                'items'   => array_map( 'dp_toolbox_fb_naar_array', array_slice( $rijen, 0, 50 ) ),
            ];
        },
        'permission_callback' => 'dp_toolbox_fb_mag_afhandelen',
        'meta'                => $meta( true ),
    ] );

    wp_register_ability( 'dp-toolbox/feedback-afhandelen', [
        'label'               => 'Feedback: afhandelen',
        'description'         => 'Zet feedback op afgehandeld, of heropent die met afgehandeld op false. Alleen de status verandert, nooit de tekst. Alleen voor beheerders.',
        'category'            => 'site',
        'input_schema'        => [
            'type'                 => 'object',
            'properties'           => [
                'id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                'afgehandeld' => [ 'type' => 'boolean', 'default' => true ],
            ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ],
        'output_schema'       => [ 'type' => 'object' ],
        'execute_callback'    => function ( $input ) {
            $afgehandeld = ! isset( $input['afgehandeld'] ) || (bool) $input['afgehandeld'];
            $resultaat   = dp_toolbox_fb_zet_status( (int) $input['id'], $afgehandeld );
            return is_wp_error( $resultaat ) ? $resultaat : [ 'id' => (int) $input['id'], 'afgehandeld' => $afgehandeld ];
        },
        'permission_callback' => 'dp_toolbox_fb_mag_afhandelen',
        'meta'                => $meta( false ),
    ] );
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
