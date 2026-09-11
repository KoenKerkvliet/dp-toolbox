<?php
/**
 * Design Pixels-menugroep — gedeeld bestand, versie 3.
 *
 * Zet de menu's van alle DP-plugins in de beheerzijbalk bij elkaar, tussen een dunne lijn
 * met het label "Design Pixels" en een lijn eronder (zoals Crocoblock dat met zijn plugins
 * doet). Beide lijnen zijn echte menu-scheidingen, net als die van WordPress. Het blok
 * komt op de plek van het eerste DP-menu dat de gebruiker te zien krijgt; de volgorde
 * daarbinnen blijft zoals hij was (ook na slepen in Menu Sorter). Een klant ziet de groep
 * dus alleen rond de DP-menu's die hij mag gebruiken.
 *
 * Dit bestand zit ongewijzigd in elke DP-plugin. Staan er meerdere op een site, dan doet
 * alleen de hoogste versie het werk. Een DP-plugin die hieronder nog niet genoemd wordt,
 * meldt zijn menu aan met:
 *     add_filter( 'dp_menu_groep', function ( $slugs ) { $slugs[] = 'mijn-menu-slug'; return $slugs; } );
 *
 * Aanpassen? Verhoog dan het versienummer in de functienamen (_3 → _4) en in de sleutel
 * van $GLOBALS['dp_menu_groep_versies'], en kopieer het bestand naar alle DP-plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$GLOBALS['dp_menu_groep_versies'][3] = 'dp_menu_groep_3_start';

if ( ! function_exists( 'dp_menu_groep_3_start' ) ) {

    function dp_menu_groep_3_start() {
        add_filter( 'custom_menu_order', '__return_true' );
        add_filter( 'menu_order', 'dp_menu_groep_3_ordenen', 999 ); // na Menu Sorter en andere plugins
        add_action( 'admin_head', 'dp_menu_groep_3_css' );
        add_action( 'adminmenu', 'dp_menu_groep_3_kleur' );         // direct na het menu
    }

    /** Menu-slugs van de DP-plugins. */
    function dp_menu_groep_3_slugs() {
        $slugs = apply_filters( 'dp_menu_groep', [
            'dp-toolbox',
            'dp-webshop',
            'dp-analytics',
            'dp-handleiding',
            'dp-chat',
            'dp-cookie-consent',
        ] );
        return array_values( array_unique( array_filter( array_map( 'strval', (array) $slugs ) ) ) );
    }

    /**
     * WordPress' eigen filter voor de menuvolgorde: draait nadat alle plugins hun menu's
     * hebben toegevoegd of verborgen, dus we zien precies wat deze gebruiker te zien krijgt.
     */
    function dp_menu_groep_3_ordenen( $volgorde ) {
        global $menu;
        $volgorde = array_values( (array) $volgorde );
        $dp       = array_values( array_intersect( $volgorde, dp_menu_groep_3_slugs() ) );
        if ( ! $dp ) {
            return $volgorde;
        }

        $plek = array_search( $dp[0], $volgorde, true );
        $rest = array_values( array_diff( $volgorde, $dp ) );

        // De lijnen zijn echte menu-scheidingen, net als die van WordPress zelf. Geen opmaak
        // op de menu-items: daar hangt WordPress bij hover zijn eigen pijltje aan (::after).
        $scheiding = function ( $slug ) use ( $menu ) {
            foreach ( (array) $menu as $item ) {
                if ( ( $item[2] ?? '' ) === $slug ) {
                    return false !== stripos( (string) ( $item[4] ?? '' ), 'wp-menu-separator' );
                }
            }
            return false;
        };
        // Staat er al een scheiding vlak boven de groep, dan komt onze kop daarvóór: van twee
        // scheidingen op rij haalt WordPress de tweede weg, en dat moet de zijne zijn.
        $kop_plek = ( $plek > 0 && $scheiding( $rest[ $plek - 1 ] ?? '' ) ) ? $plek - 1 : $plek;

        array_splice( $rest, $plek, 0, $dp );
        array_splice( $rest, $plek + count( $dp ), 0, [ 'separator-dp-eind' ] );
        array_splice( $rest, $kop_plek, 0, [ 'separator-dp-kop' ] );

        $menu['separator-dp-kop']  = [ '', 'read', 'separator-dp-kop', '', 'wp-menu-separator dp-menu-scheiding dp-menu-scheiding--kop' ];
        $menu['separator-dp-eind'] = [ '', 'read', 'separator-dp-eind', '', 'wp-menu-separator dp-menu-scheiding' ];

        foreach ( (array) $menu as $i => $item ) {
            if ( in_array( $item[2] ?? '', $dp, true ) ) {
                $menu[ $i ][4] = trim( ( $item[4] ?? '' ) . ' dp-menu-groep' );
            }
        }
        return $rest;
    }

    function dp_menu_groep_3_css() {
        ?>
        <style id="dp-menu-groep">
            #adminmenu li.wp-menu-separator.dp-menu-scheiding { position: relative; height: 1px; margin: 12px 0; padding: 0; }
            #adminmenu li.wp-menu-separator.dp-menu-scheiding--kop { margin-top: 19px; }
            #adminmenu li.dp-menu-scheiding div.separator {
                height: 1px; margin: 0 12px; padding: 0;
                background: var(--dp-menu-lijn, rgba(240, 246, 252, .16));
            }
            #adminmenu li.dp-menu-scheiding--kop::before {
                content: "Design Pixels"; position: absolute; left: 14px; top: -7px;
                padding: 0 6px; border: 1px solid var(--dp-menu-lijn, rgba(240, 246, 252, .16)); border-radius: 4px;
                background: var(--dp-menu-achter, #1d2327); color: var(--dp-menu-tekst, rgba(240, 246, 252, .6));
                font-size: 9px; font-weight: 600; line-height: 13px; letter-spacing: .08em; text-transform: uppercase;
                white-space: nowrap; pointer-events: none;
            }
            /* Ingeklapte zijbalk: alleen de lijnen. */
            .folded #adminmenu li.dp-menu-scheiding--kop::before { display: none; }
            .folded #adminmenu li.wp-menu-separator.dp-menu-scheiding--kop { margin-top: 12px; }
            .folded #adminmenu li.dp-menu-scheiding div.separator { margin: 0 8px; }
            @media only screen and (min-width: 783px) and (max-width: 960px) {
                .auto-fold #adminmenu li.dp-menu-scheiding--kop::before { display: none; }
                .auto-fold #adminmenu li.wp-menu-separator.dp-menu-scheiding--kop { margin-top: 12px; }
                .auto-fold #adminmenu li.dp-menu-scheiding div.separator { margin: 0 8px; }
            }
        </style>
        <?php
    }

    /**
     * Label en lijn in de kleuren van het menu: achtergrond overnemen (voor het label op de
     * lijn) en bij een licht menu donkere lijn en tekst. Werkt voor elk kleurenschema,
     * ook als een andere plugin het menu zelf inkleurt.
     */
    function dp_menu_groep_3_kleur() {
        ?>
        <script>
        (function () {
            var m = document.getElementById('adminmenu');
            if (!m) return;
            var c = getComputedStyle(m).backgroundColor, d = c.match(/\d+(\.\d+)?/g);
            if (!d || (d.length > 3 && +d[3] === 0)) return;
            m.style.setProperty('--dp-menu-achter', c);
            if (0.299 * d[0] + 0.587 * d[1] + 0.114 * d[2] > 150) {
                m.style.setProperty('--dp-menu-lijn', 'rgba(0, 0, 0, .15)');
                m.style.setProperty('--dp-menu-tekst', 'rgba(0, 0, 0, .55)');
            }
        })();
        </script>
        <?php
    }
}

/** Kies de hoogste versie die op de site staat. Eén keer, welke DP-plugin ook eerst laadt. */
if ( ! function_exists( 'dp_menu_groep_kies' ) ) {
    function dp_menu_groep_kies() {
        $versies = (array) ( $GLOBALS['dp_menu_groep_versies'] ?? [] );
        if ( ! $versies ) {
            return;
        }
        krsort( $versies );
        $start = reset( $versies );
        if ( is_callable( $start ) ) {
            call_user_func( $start );
        }
    }
    add_action( 'admin_menu', 'dp_menu_groep_kies', 0 );
}
