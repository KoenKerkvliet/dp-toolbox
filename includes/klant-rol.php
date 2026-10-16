<?php
/**
 * DP Toolbox — de rol "Klant", geladen door modules die iets voor klanten doen (nu: Feedback).
 *
 * Dit bestand wordt alleen ingelezen als zo'n module aan staat, dus de rol bestaat niet op sites
 * die er niets mee doen. Het kan meer dan eens worden ingelezen (een losse kopie in een
 * module-in-ontwikkeling, of een tweede module die het ook laadt). Daarom staat ALLES binnen
 * één voorwaardelijk blok: PHP declareert functies op het hoogste niveau van een bestand al bij
 * het inlezen, vóór een vroege `return` wordt uitgevoerd. Een `return` bovenaan beschermt dus
 * niet tegen "Cannot redeclare" (zo is het misgegaan op 16 okt 2026); een functie binnen een
 * if-blok wordt pas gedeclareerd als dat blok echt draait.
 *
 * Een klant kan inloggen en verder alleen wat de modules hem geven. Geen wp-admin, geen
 * adminbalk, en na het inloggen direct terug naar de website. Een thema of snippet kan één
 * beheerscherm openen via het filter `dp_toolbox_klant_admin_paginas`.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'DP_TOOLBOX_KLANT_ROL' ) ) {

    define( 'DP_TOOLBOX_KLANT_ROL', 'dp_klant' );

    /**
     * Alleen wie UITSLUITEND de rol Klant heeft. Met een extra rol (bijvoorbeeld Editor) houd je je gewone toegang.
     */
    function dp_toolbox_klant_is( $user = null ) {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $rollen = (array) $user->roles;
        return in_array( DP_TOOLBOX_KLANT_ROL, $rollen, true ) && ! array_diff( $rollen, [ DP_TOOLBOX_KLANT_ROL ] );
    }

    /**
     * Een klant heeft geen adminbalk, dus de modules tonen zelf een uitlogknop.
     */
    function dp_toolbox_klant_uitlog_url() {
        return dp_toolbox_klant_is() ? wp_logout_url( home_url( '/' ) ) : '';
    }

    add_action( 'init', function () {
        $rol = get_role( DP_TOOLBOX_KLANT_ROL );

        if ( ! $rol ) {
            add_role( DP_TOOLBOX_KLANT_ROL, 'Klant', [ 'read' => true ] );
        } elseif ( ! $rol->has_cap( 'read' ) ) {
            $rol->add_cap( 'read' );
        }
    }, 5 );

    add_filter( 'show_admin_bar', function ( $tonen ) {
        return dp_toolbox_klant_is() ? false : $tonen;
    } );

    add_filter( 'login_redirect', function ( $naar, $gevraagd, $user ) {
        return $user instanceof WP_User && dp_toolbox_klant_is( $user ) ? home_url( '/' ) : $naar;
    }, 10, 3 );

    /**
     * Beheerschermen waar een klant WEL in mag, als slugs van admin.php?page=… (bijvoorbeeld een
     * JetEngine options page). Standaard geen. Een thema of snippet voegt ze toe via het filter
     * `dp_toolbox_klant_admin_paginas`; de pagina moet zelf ook een recht eisen dat de klant heeft.
     */
    function dp_toolbox_klant_admin_paginas() {
        return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'dp_toolbox_klant_admin_paginas', [] ) ) ) );
    }

    function dp_toolbox_klant_admin_pagina_nu() {
        global $pagenow;
        $pagina = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Alleen een toegangscheck; de pagina zelf controleert recht en nonce.
        return 'admin.php' === $pagenow && '' !== $pagina && in_array( $pagina, dp_toolbox_klant_admin_paginas(), true );
    }

    add_action( 'admin_init', function () {
        if ( ! dp_toolbox_klant_is() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        // Foto's uploaden vanuit de mediabibliotheek op de site loopt via async-upload.php.
        global $pagenow;
        if ( 'async-upload.php' === $pagenow && current_user_can( 'upload_files' ) ) {
            return;
        }

        if ( dp_toolbox_klant_admin_pagina_nu() ) {
            return;
        }

        wp_safe_redirect( home_url( '/' ) );
        exit;
    }, 1 );

    // In het beheer toont WordPress de adminbalk altijd (het filter show_admin_bar telt daar niet), dus verberg hem voor een klant.
    add_action( 'admin_head', function () {
        if ( dp_toolbox_klant_is() ) {
            echo '<style>#wpadminbar{display:none!important}html.wp-toolbar{padding-top:0!important}</style>' . "\n";
        }
    } );

    // In het beheer ziet een klant alleen zijn eigen scherm(en) en een weg terug naar de website.
    add_action( 'admin_menu', function () {
        if ( ! dp_toolbox_klant_is() ) {
            return;
        }

        global $menu, $submenu;
        $toegestaan = dp_toolbox_klant_admin_paginas();

        foreach ( (array) $menu as $sleutel => $item ) {
            if ( ! in_array( $item[2], $toegestaan, true ) ) {
                unset( $menu[ $sleutel ] );
            }
        }
        foreach ( array_keys( (array) $submenu ) as $ouder ) {
            if ( ! in_array( $ouder, $toegestaan, true ) ) {
                unset( $submenu[ $ouder ] );
            }
        }

        $menu[1] = [ 'Terug naar de website', 'read', home_url( '/' ), '', 'menu-top dp-klant-terug', 'dp-klant-terug', 'dashicons-arrow-left-alt' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Bewust: het menu van een klant opbouwen.
    }, 9999 );

    /**
     * Staat geen van beide modules meer aan, dan de rol opruimen — maar alleen als er geen accounts
     * meer aan hangen. Werkt omdat de module nog geladen is in het verzoek waarmee de lijst wordt opgeslagen.
     */
    add_action( 'update_option_dp_toolbox_enabled_modules', function ( $oud, $nieuw ) {
        $modules = [ 'feedback', 'bewerken' ];
        if ( ! array_intersect( $modules, (array) $oud ) || array_intersect( $modules, (array) $nieuw ) ) {
            return;
        }
        if ( ! get_users( [ 'role' => DP_TOOLBOX_KLANT_ROL, 'number' => 1, 'fields' => 'ID' ] ) ) {
            remove_role( DP_TOOLBOX_KLANT_ROL );
        }
    }, 10, 2 );
}
