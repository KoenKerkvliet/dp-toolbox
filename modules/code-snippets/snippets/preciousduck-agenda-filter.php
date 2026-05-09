<?php
/**
 * Name: Komende agenda — verberg verlopen items
 * Description: Filtert Bricks loop queries voor 'agenda' zodat alleen items met datum >= nu getoond worden, gesorteerd op datum oplopend (eerstvolgende eerst). Werkt op render-tijd via Bricks' query_vars filter — survived Bricks editor saves.
 * Sites: preciousduck.s5-tastewp.com
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'bricks/posts/query_vars', function ( $query_vars, $settings, $element_id, $element_name = null ) {
    $post_type = $query_vars['post_type'] ?? null;
    $is_agenda = ( $post_type === 'agenda' )
        || ( is_array( $post_type ) && in_array( 'agenda', $post_type, true ) );

    if ( ! $is_agenda ) {
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
