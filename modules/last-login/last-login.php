<?php
/**
 * Module Name: Laatste Login
 * Description: Toont per gebruiker wanneer die voor het laatst inlogde, met een filter op wie nog nooit binnen is geweest.
 * Category: users
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const DP_TOOLBOX_LL_META = 'dp_last_login';

/* ================================================================== */
/*  Registreren                                                        */
/* ================================================================== */

add_action( 'wp_login', function ( $user_login, $user = null ) {
    if ( ! $user instanceof WP_User ) {
        $user = get_user_by( 'login', $user_login );
    }
    if ( $user instanceof WP_User && $user->ID ) {
        update_user_meta( $user->ID, DP_TOOLBOX_LL_META, time() );
    }
}, 10, 2 );

/**
 * Eenmalige inhaalslag bij het aanzetten van de module.
 *
 * WordPress houdt zelf niets bij, dus voor bestaande gebruikers weten we in
 * principe niets. Eén uitzondering: wie nu een actieve sessie heeft, heeft een
 * inlogmoment staan in `session_tokens`. Dat lezen we uit, zodat de kolom niet
 * op dag één volledig leeg staat.
 *
 * We leggen ook vast vanaf wanneer we meten. Zonder die datum zou een lege cel
 * "nooit ingelogd" suggereren, terwijl het net zo goed "van vóór onze tijd" kan
 * zijn — en op dat verschil ga je iemand bellen.
 */
function dp_toolbox_ll_backfill() {
    if ( get_option( 'dp_toolbox_ll_since' ) ) {
        return;
    }

    foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $u ) {
        $tokens = get_user_meta( $u->ID, 'session_tokens', true );
        if ( ! is_array( $tokens ) || ! $tokens ) {
            continue;
        }

        $laatste = 0;
        foreach ( $tokens as $token ) {
            $laatste = max( $laatste, (int) ( $token['login'] ?? 0 ) );
        }

        if ( $laatste > 0 ) {
            update_user_meta( $u->ID, DP_TOOLBOX_LL_META, $laatste );
        }
    }

    update_option( 'dp_toolbox_ll_since', time(), false );
}
add_action( 'admin_init', 'dp_toolbox_ll_backfill' );

function dp_toolbox_ll_since() {
    return (int) get_option( 'dp_toolbox_ll_since', 0 );
}

/* ================================================================== */
/*  Kolom in de gebruikerslijst                                        */
/* ================================================================== */

add_filter( 'manage_users_columns', function ( $columns ) {
    $columns['dp_last_login'] = 'Laatst ingelogd';
    return $columns;
} );

add_filter( 'manage_users_custom_column', function ( $output, $column, $user_id ) {
    if ( 'dp_last_login' !== $column ) {
        return $output;
    }

    $ts = (int) get_user_meta( $user_id, DP_TOOLBOX_LL_META, true );

    if ( ! $ts ) {
        $sinds = dp_toolbox_ll_since();
        $titel = $sinds
            ? 'Niet ingelogd sinds ' . wp_date( 'j F Y', $sinds ) . ', toen we dit gingen bijhouden.'
            : 'Nog geen inlog geregistreerd.';
        return '<span class="dp-ll-nooit" title="' . esc_attr( $titel ) . '">Nog niet</span>';
    }

    $verschil = human_time_diff( $ts, time() );
    $volledig = wp_date( 'j F Y, H:i', $ts );

    return '<span title="' . esc_attr( $volledig ) . '">' . esc_html( $verschil ) . ' geleden</span>';
}, 10, 3 );

add_filter( 'manage_users_sortable_columns', function ( $columns ) {
    $columns['dp_last_login'] = 'dp_last_login';
    return $columns;
} );

add_action( 'pre_get_users', function ( $query ) {
    if ( ! is_admin() ) {
        return;
    }

    /*
     * Sorteren op een meta-veld gooit standaard iedereen zonder dat veld uit de
     * lijst. Met een OR-meta_query blijven ze staan — juist zij zijn interessant.
     */
    if ( 'dp_last_login' === $query->get( 'orderby' ) ) {
        $query->set( 'meta_query', [
            'relation' => 'OR',
            [ 'key' => DP_TOOLBOX_LL_META, 'compare' => 'EXISTS' ],
            [ 'key' => DP_TOOLBOX_LL_META, 'compare' => 'NOT EXISTS' ],
        ] );
        $query->set( 'orderby', 'meta_value_num' );
    }

    if ( ! empty( $_GET['dp_ll_filter'] ) && 'nooit' === $_GET['dp_ll_filter'] ) {
        $query->set( 'meta_query', [
            [ 'key' => DP_TOOLBOX_LL_META, 'compare' => 'NOT EXISTS' ],
        ] );
    }
} );

/* ---- Snelfilter boven de lijst ---- */

add_filter( 'views_users', function ( $views ) {
    $aantal = dp_toolbox_ll_aantal_nooit();
    if ( ! $aantal ) {
        return $views;
    }

    $actief = ! empty( $_GET['dp_ll_filter'] ) && 'nooit' === $_GET['dp_ll_filter'];
    $url    = add_query_arg( 'dp_ll_filter', 'nooit', admin_url( 'users.php' ) );

    $views['dp_ll_nooit'] = sprintf(
        '<a href="%s"%s>Nog niet ingelogd <span class="count">(%d)</span></a>',
        esc_url( $url ),
        $actief ? ' class="current" aria-current="page"' : '',
        $aantal
    );

    return $views;
} );

function dp_toolbox_ll_aantal_nooit() {
    $q = new WP_User_Query( [
        'meta_query' => [ [ 'key' => DP_TOOLBOX_LL_META, 'compare' => 'NOT EXISTS' ] ],
        'fields'      => 'ID',
        'number'      => 1,   // we willen alleen het totaal, niet de hele lijst
        'count_total' => true,
    ] );

    return (int) $q->get_total();
}

add_action( 'admin_head-users.php', function () {
    echo '<style>
        .column-dp_last_login { width: 150px; }
        .dp-ll-nooit { color: #b32d2e; font-weight: 600; }
    </style>';
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
