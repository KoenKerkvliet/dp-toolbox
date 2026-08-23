<?php
/**
 * Name: Komende evenementen — verberg verlopen events
 * Description: Filtert Bricks loop queries voor 'evenementen' zodat alleen events met datum >= nu getoond worden, gesorteerd op datum oplopend (eerstvolgende eerst). Werkt op render-tijd via Bricks' query_vars filter — survived Bricks editor saves.
 * Sites: abedia.nl
 * Status: active
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'bricks/posts/query_vars', function ( $query_vars, $settings, $element_id, $element_name = null ) {
    $post_type = $query_vars['post_type'] ?? null;
    $is_evenementen = ( $post_type === 'evenementen' )
        || ( is_array( $post_type ) && in_array( 'evenementen', $post_type, true ) );

    if ( ! $is_evenementen ) {
        return $query_vars;
    }

    $meta_query = (array) ( $query_vars['meta_query'] ?? [] );
    $meta_query[] = [
        'key'     => 'datum',
        'value'   => time(),
        'compare' => '>=',
        'type'    => 'NUMERIC',
    ];
    $query_vars['meta_query'] = $meta_query;

    $query_vars['meta_key'] = 'datum';
    $query_vars['orderby']  = 'meta_value_num';
    $query_vars['order']    = 'ASC';

    return $query_vars;
}, 10, 4 );
