<?php
/**
 * Name: Design Pixels — URL-consolidatie
 * Description: Ruimt dubbele en lege URL's op die WordPress vanzelf aanmaakt: /portfolio-items → /portfolio/, auteursarchieven → /category/blog/, en losse /klanten/<slug>-pagina's geven een echte 404 in plaats van een dunne pagina die geïndexeerd kan worden.
 * Sites: designpixels.nl,zoomthroat.s2-tastewp.com
 * Status: active
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Waar hoort een bezoeker van een auteursarchief naartoe?
 *
 * De Bricks-site gebruikt /category/blog/ als artikelenoverzicht, de Etch-herbouw
 * een echte pagina /kennisbank/. Hardcoden betekent dat deze redirect stilletjes
 * op een 404 uitkomt zodra de site overgezet wordt, dus we zoeken het op.
 */
function dp_artikelen_overzicht_url() {
    $posts_page = (int) get_option( 'page_for_posts' );
    if ( $posts_page && 'publish' === get_post_status( $posts_page ) ) {
        return get_permalink( $posts_page );
    }

    $kennisbank = get_page_by_path( 'kennisbank' );
    if ( $kennisbank && 'publish' === $kennisbank->post_status ) {
        return get_permalink( $kennisbank );
    }

    $blog = get_category_by_slug( 'blog' );
    if ( $blog && ! is_wp_error( $blog ) && $blog->count > 0 ) {
        return get_category_link( $blog->term_id );
    }

    return home_url( '/' );
}

add_action( 'template_redirect', static function (): void {
    $request_path = untrailingslashit( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );

    if ( '/portfolio-items' === $request_path ) {
        wp_safe_redirect( home_url( '/portfolio/' ), 301 );
        return;
    }

    if ( is_author() ) {
        wp_safe_redirect( dp_artikelen_overzicht_url(), 301 );
        return;
    }

    if ( preg_match( '#^/klanten/[^/]+$#', $request_path ) ) {
        global $wp_query;
        $GLOBALS['dp_force_404_template'] = true;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
    }
}, -999 );

add_filter( 'redirect_canonical', static function ( $redirect_url ) {
    $request_path = untrailingslashit( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );

    if ( preg_match( '#^/klanten/[^/]+$#', $request_path ) ) {
        return false;
    }

    return $redirect_url;
}, -999 );

add_filter( 'template_include', static function ( string $template ): string {
    if ( ! empty( $GLOBALS['dp_force_404_template'] ) ) {
        $not_found_template = get_404_template();
        if ( $not_found_template ) {
            return $not_found_template;
        }
    }

    return $template;
}, 99 );
