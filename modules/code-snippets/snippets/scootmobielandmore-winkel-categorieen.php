<?php
/**
 * Name: Winkel — categoriekaarten terug op /winkel/
 * Description: Sinds WooCommerce de Winkel-pagina (post) als queried object op het productarchief zet, vraagt JetWooBuilder's Categories Grid (Show by: Current Subcategories) de kinderen van dat post-ID op en blijft de rij leeg. Zet op de winkelpagina parent terug naar 0 (hoofdcategorieën); categoriepagina's blijven ongewijzigd.
 * Sites: scootmobielandmore.nl
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'get_terms_args', function ( $args, $taxonomies ) {
    if ( ! function_exists( 'is_shop' ) || ! is_shop() || is_product_taxonomy() ) {
        return $args;
    }
    if ( ! in_array( 'product_cat', (array) $taxonomies, true ) ) {
        return $args;
    }

    $shop_id = (int) wc_get_page_id( 'shop' );
    if ( $shop_id <= 0 || ! isset( $args['parent'] ) || (int) $args['parent'] !== $shop_id ) {
        return $args;
    }

    // Een echte categorie met hetzelfde ID als de Winkel-pagina niet overschrijven.
    if ( term_exists( $shop_id, 'product_cat' ) ) {
        return $args;
    }

    $args['parent'] = 0;

    return $args;
}, 10, 2 );
