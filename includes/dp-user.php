<?php
/**
 * DP-user check — plugin-breed.
 *
 * Staat bewust in een eigen bestand en niet in dp-toolbox.php. Op 20 september
 * 2026 knipte een malwarescanner op de hosting van een klantsite deze functie
 * uit dp-toolbox.php (false positive) terwijl de aanroepen bleven staan. Elke
 * frontendpagina crashte daarna fataal op wp_head en de site lag anderhalf uur
 * plat. Eén bestand per verantwoordelijkheid maakt zo'n ingreep onschadelijk:
 * raakt dit bestand leeg of weg, dan vallen de function_exists()-checks bij de
 * aanroepers terug op "geen DP-user" en blijft de site gewoon draaien.
 *
 * @package DP_Toolbox
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Is deze gebruiker een Design Pixels-beheerder?
 *
 * Uitsluitend op e-maildomein. Bewust geen instelling per rol of per gebruiker:
 * dat is een deur die per ongeluk open kan blijven staan.
 *
 * @param int|null $user_id Gebruiker, of null voor de huidige.
 * @return bool
 */
function dp_toolbox_is_dp_user( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return apply_filters( 'dp_toolbox_is_dp_user', false, $user_id );
    }

    $user  = get_userdata( $user_id );
    $is_dp = $user && ! empty( $user->user_email )
        && str_ends_with( strtolower( trim( $user->user_email ) ), '@designpixels.nl' );

    return apply_filters( 'dp_toolbox_is_dp_user', $is_dp, $user_id );
}
