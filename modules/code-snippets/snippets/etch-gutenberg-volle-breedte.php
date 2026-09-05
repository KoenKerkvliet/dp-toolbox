<?php
/**
 * Name: Etch — volle breedte in de Gutenberg-editor
 * Description: Haalt de smalle contentbreedte weg bij Etch-blokken in de blok-editor, zodat de klant daar ziet wat de frontend toont. Etch Theme zet in theme.json contentSize 745px. De frontend negeert dat (de templates renderen post-content met layout type "default" = flow, géén max-width), maar de editor maakt in rendering mode "post-only" van de root-container een constrained layout met exact die 745px — waardoor elk top-level Etch-blok wordt afgeknepen tot een kolommetje. Etch' eigen etch-gutenberg-overwrites.css repareert alleen de variant mét zichtbare template. Deze snippet voegt één CSS-regel toe aan de editor-canvas die de max-width weghaalt, uitsluitend voor blokken van Etch zelf.
 * Sites: *
 * Status: active
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Zet de opgelegde contentbreedte uit voor top-level Etch-blokken in de editor.
 *
 * De editor genereert voor de root-container deze regel (met de waarde uit
 * theme.json er letterlijk in gebakken):
 *
 *     .block-editor-block-list__layout.is-root-container
 *       > :where(:not(.alignleft):not(.alignright):not(.alignfull))
 *     { max-width: 745px; margin-left: auto !important; margin-right: auto !important; }
 *
 * LANDMIJN — het via PHP aanpassen van __experimentalFeatures.layout op
 * block_editor_settings_all werkt NIET. De editor haalt die layout-instelling
 * client-side alsnog op via de REST-endpoint voor global styles
 * (/wp/v2/global-styles/themes/<theme>) en overschrijft daarmee wat de
 * server meegaf. Geverifieerd in de browser: getSettings().__experimentalFeatures
 * .layout bleef 745px staan terwijl get_block_editor_settings() server-side
 * netjes 100% teruggaf. Het via theme.json aanpassen zou wél werken, maar
 * raakt ook de frontend en wordt bij elke Etch-Theme-update overschreven.
 *
 * Daarom een CSS-override op de canvas. Specificiteit: het selectordeel van de
 * kernregel weegt (0,2,0) — `:where()` telt voor niets — en deze regel weegt
 * (0,3,0) door het attribuutdeel. Dus geen `!important` nodig.
 *
 * De selector scopet zichzelf op `[data-type^="etch/"]`, wat twee dingen
 * oplost tegelijk:
 *   - alle Etch-blocktypes tegelijk gedekt (element, component, loop, condition);
 *   - een gewoon bericht dat in Gutenberg is geschreven houdt zijn 745px
 *     leesbreedte, want core-blokken matchen niet. Daar is die smalle maat
 *     juist de gewenste regellengte.
 *
 * @param array                   $settings Editor-settings.
 * @param WP_Block_Editor_Context $context  Context van de editor (ongebruikt).
 * @return array
 */
function dp_toolbox_etch_gutenberg_volle_breedte( $settings, $context ) {
    $css = '.block-editor-block-list__layout.is-root-container > [data-type^="etch/"]{max-width:none;}';

    if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
        $settings['styles'] = [];
    }
    $settings['styles'][] = [ 'css' => $css ];

    return $settings;
}
add_filter( 'block_editor_settings_all', 'dp_toolbox_etch_gutenberg_volle_breedte', 20, 2 );
