<?php
/**
 * DP Toolbox — Gedeelde branding
 *
 * Bepaalt welk logo, welke kleuren en welke credit er op de pagina's staan die
 * bezoekers van de site te zien krijgen: de onderhoudspagina en de
 * WordPress-loginpagina. Eén instelling, zodat die twee nooit uit de pas lopen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 'dp' = Design Pixels-branding, 'client' = de branding van de site zelf.
 *
 * Default is 'dp', zodat bestaande sites er na een update niet ineens anders
 * uitzien dan de beheerder gewend is.
 */
function dp_toolbox_branding_mode() {
    $mode = get_option( 'dp_toolbox_branding_mode', 'dp' );
    return in_array( $mode, [ 'client', 'dp' ], true ) ? $mode : 'dp';
}

function dp_toolbox_branding_is_client() {
    return 'client' === dp_toolbox_branding_mode();
}

/**
 * Logo voor de publieke pagina's.
 *
 * In klantmodus: het logo uit het thema, anders het site-icoon, anders niets —
 * en dan tonen de pagina's de sitenaam als tekst. Leeg teruggeven is dus een
 * geldig antwoord, geen fout.
 */
function dp_toolbox_branding_logo_url() {
    if ( ! dp_toolbox_branding_is_client() ) {
        return DP_TOOLBOX_URL . 'assets/dp-logo.webp';
    }

    $custom_logo_id = (int) get_theme_mod( 'custom_logo' );
    if ( $custom_logo_id ) {
        $src = wp_get_attachment_image_url( $custom_logo_id, 'full' );
        if ( $src ) {
            return $src;
        }
    }

    $icon = get_site_icon_url( 512 );

    return $icon ? $icon : '';
}

/**
 * Kleuren voor de publieke pagina's.
 *
 * In klantmodus neutraal donkergrijs in plaats van het DP-paars: de echte
 * huisstijlkleuren van een klant kunnen we niet raden. Wie ze wél wil zetten,
 * gebruikt de filter hieronder.
 */
function dp_toolbox_branding_colors() {
    if ( dp_toolbox_branding_is_client() ) {
        $colors = [
            'accent'       => '#2c3338',
            'accent_hover' => '#3c434a',
            'gradient'     => 'linear-gradient(135deg, #1d2327 0%, #2c3338 40%, #3c434a 100%)',
        ];
    } else {
        $colors = [
            'accent'       => '#281E5D',
            'accent_hover' => '#4a3a8a',
            'gradient'     => 'linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%)',
        ];
    }

    /**
     * Per site aanpasbaar, bijvoorbeeld om de echte merkkleuren van een klant
     * te gebruiken zonder de plugin te forken.
     */
    return apply_filters( 'dp_toolbox_branding_colors', $colors, dp_toolbox_branding_mode() );
}

function dp_toolbox_branding_color( $key ) {
    $colors = dp_toolbox_branding_colors();
    return $colors[ $key ] ?? '';
}

/**
 * De credit onderaan de onderhoudspagina. Leeg in klantmodus.
 */
function dp_toolbox_branding_credit_html() {
    if ( dp_toolbox_branding_is_client() ) {
        return '';
    }

    return '<a href="https://designpixels.nl" target="_blank" rel="noopener">Design Pixels</a>';
}
