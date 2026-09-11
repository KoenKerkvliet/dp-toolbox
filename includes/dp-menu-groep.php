<?php
/**
 * Design Pixels-menugroep — gedeeld bestand, versie 2.
 *
 * Zet de menu's van alle DP-plugins in de beheerzijbalk bij elkaar, tussen een dunne lijn
 * met het label "Design Pixels" en een lijn eronder (zoals Crocoblock dat met zijn plugins
 * doet). Het blok komt op de plek van het eerste DP-menu; de volgorde daarbinnen blijft zoals hij was
 * (ook na slepen in Menu Sorter). De lijn hangt boven het eerste DP-menu dat de gebruiker
 * te zien krijgt, dus een klant ziet hem alleen boven de DP-menu's die hij mag gebruiken.
 *
 * Dit bestand zit ongewijzigd in elke DP-plugin. Staan er meerdere op een site, dan doet
 * alleen de hoogste versie het werk. Een DP-plugin die hieronder nog niet genoemd wordt,
 * meldt zijn menu aan met:
 *     add_filter( 'dp_menu_groep', function ( $slugs ) { $slugs[] = 'mijn-menu-slug'; return $slugs; } );
 *
 * Aanpassen? Verhoog dan het versienummer in de functienamen (_2 → _3) en in de sleutel
 * van $GLOBALS['dp_menu_groep_versies'], en kopieer het bestand naar alle DP-plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$GLOBALS['dp_menu_groep_versies'][2] = 'dp_menu_groep_2_start';

if ( ! function_exists( 'dp_menu_groep_2_start' ) ) {

    function dp_menu_groep_2_start() {
        add_filter( 'custom_menu_order', '__return_true' );
        add_filter( 'menu_order', 'dp_menu_groep_2_ordenen', 999 ); // na Menu Sorter en andere plugins
        add_action( 'admin_head', 'dp_menu_groep_2_css' );
        add_action( 'adminmenu', 'dp_menu_groep_2_kleur' );         // direct na het menu
    }

    /** Menu-slugs van de DP-plugins. */
    function dp_menu_groep_2_slugs() {
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
    function dp_menu_groep_2_ordenen( $volgorde ) {
        global $menu;
        $volgorde = array_values( (array) $volgorde );
        $dp       = array_values( array_intersect( $volgorde, dp_menu_groep_2_slugs() ) );
        if ( ! $dp ) {
            return $volgorde;
        }

        $plek = array_search( $dp[0], $volgorde, true );
        $rest = array_values( array_diff( $volgorde, $dp ) );
        array_splice( $rest, $plek, 0, $dp );

        // Klassen voor de opmaak; WordPress zet deze op het <li> van het menu-item. De lijn
        // onder de groep hangt aan het item ná de groep: zo werkt het ook als de groep uit
        // één menu bestaat (dat item gebruikt zijn ::before en ::after al voor lijn en label).
        $na = $rest[ $plek + count( $dp ) ] ?? '';
        foreach ( (array) $menu as $i => $item ) {
            $slug  = $item[2] ?? '';
            $extra = '';
            if ( in_array( $slug, $dp, true ) ) {
                $extra = ' dp-menu-groep' . ( $slug === $dp[0] ? ' dp-menu-groep-eerste' : '' );
            } elseif ( $slug !== '' && $slug === $na ) {
                $extra = ' dp-menu-groep-na';
            }
            if ( $extra ) {
                $menu[ $i ][4] = trim( ( $item[4] ?? '' ) . $extra );
            }
        }
        return $rest;
    }

    function dp_menu_groep_2_css() {
        ?>
        <style id="dp-menu-groep">
            #adminmenu li.dp-menu-groep-eerste { margin-top: 24px; }
            #adminmenu li.dp-menu-groep-eerste::before {
                content: ""; position: absolute; left: 12px; right: 12px; top: -12px;
                border-top: 1px solid var(--dp-menu-lijn, rgba(240, 246, 252, .16));
                pointer-events: none;
            }
            #adminmenu li.dp-menu-groep-eerste::after {
                content: "Design Pixels"; position: absolute; left: 14px; top: -19px;
                padding: 0 6px; border: 1px solid var(--dp-menu-lijn, rgba(240, 246, 252, .16)); border-radius: 4px;
                background: var(--dp-menu-achter, #1d2327); color: var(--dp-menu-tekst, rgba(240, 246, 252, .6));
                font-size: 9px; font-weight: 600; line-height: 13px; letter-spacing: .08em; text-transform: uppercase;
                white-space: nowrap; pointer-events: none;
            }
            /* Lijn onder de groep, boven het eerste menu erna. */
            #adminmenu li.dp-menu-groep-na { position: relative; margin-top: 13px; }
            #adminmenu li.dp-menu-groep-na::before {
                content: ""; position: absolute; left: 12px; right: 12px; top: -7px;
                border-top: 1px solid var(--dp-menu-lijn, rgba(240, 246, 252, .16));
                pointer-events: none;
            }
            /* Ingeklapte zijbalk: alleen de lijnen. */
            .folded #adminmenu li.dp-menu-groep-eerste::after { display: none; }
            .folded #adminmenu li.dp-menu-groep-eerste::before,
            .folded #adminmenu li.dp-menu-groep-na::before { left: 8px; right: 8px; }
            @media only screen and (min-width: 783px) and (max-width: 960px) {
                .auto-fold #adminmenu li.dp-menu-groep-eerste::after { display: none; }
                .auto-fold #adminmenu li.dp-menu-groep-eerste::before,
                .auto-fold #adminmenu li.dp-menu-groep-na::before { left: 8px; right: 8px; }
            }
        </style>
        <?php
    }

    /**
     * Label en lijn in de kleuren van het menu: achtergrond overnemen (voor het label op de
     * lijn) en bij een licht menu donkere lijn en tekst. Werkt voor elk kleurenschema,
     * ook als een andere plugin het menu zelf inkleurt.
     */
    function dp_menu_groep_2_kleur() {
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
