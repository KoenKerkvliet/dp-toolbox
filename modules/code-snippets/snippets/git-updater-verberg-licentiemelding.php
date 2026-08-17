<?php
/**
 * Name: Git Updater — verberg de licentiemelding
 * Description: Onderdrukt de terugkerende "Please consider purchasing a Git Updater license"-melding in wp-admin. Git Updater hangt die melding (Messages::get_license) aan admin_notices op de plugin-, thema- en updatepagina's, en de eigen wegklik-knop werkt maar tijdelijk — WP_Dismiss_Notice zet een verlopende markering, waarna de melding terugkomt. Deze snippet haalt alleen die ene callback van de hook; foutmeldingen en de WP-Cron-melding van Git Updater blijven gewoon zichtbaar.
 * Sites: *
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Haal de licentie-aanprijzing van de notices-hook.
 *
 * Git Updater registreert die pas tijdens het opbouwen van de adminpagina, dus
 * we grijpen in op prioriteit 0 van dezelfde hook: op dat moment staat de
 * callback er wél in, maar is hij nog niet uitgevoerd. WordPress verwerkt het
 * verwijderen van callbacks tijdens het aflopen van een hook correct.
 *
 * Er wordt op klasse + methode gematcht, niet op de tekst van de melding, zodat
 * een vertaling of een herformulering door de maker dit niet omzeilt.
 */
function dp_toolbox_git_updater_verberg_licentiemelding() {
    global $wp_filter;

    $hook = is_multisite() ? 'network_admin_notices' : 'admin_notices';
    if ( empty( $wp_filter[ $hook ] ) ) {
        return;
    }

    foreach ( $wp_filter[ $hook ]->callbacks as $prioriteit => $callbacks ) {
        foreach ( $callbacks as $cb ) {
            $functie = $cb['function'] ?? null;

            if ( ! is_array( $functie ) || ! is_object( $functie[0] ) ) {
                continue;
            }
            if ( 'get_license' !== ( $functie[1] ?? '' ) ) {
                continue;
            }
            if ( false === strpos( get_class( $functie[0] ), 'Git_Updater' ) ) {
                continue;
            }

            remove_action( $hook, $functie, $prioriteit );
        }
    }
}

add_action( 'admin_notices', 'dp_toolbox_git_updater_verberg_licentiemelding', 0 );
add_action( 'network_admin_notices', 'dp_toolbox_git_updater_verberg_licentiemelding', 0 );
