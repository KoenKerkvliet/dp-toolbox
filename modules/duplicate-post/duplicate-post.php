<?php
/**
 * Module Name: Duplicate Post
 * Description: Voegt een "Dupliceer" link toe aan pagina's en berichten in het overzicht.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add "Dupliceer" link to row actions for posts and pages.
 */
add_filter( 'post_row_actions', 'dp_toolbox_duplicate_link', 10, 2 );
add_filter( 'page_row_actions', 'dp_toolbox_duplicate_link', 10, 2 );

function dp_toolbox_duplicate_link( $actions, $post ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return $actions;
    }

    $url = wp_nonce_url(
        admin_url( 'admin.php?action=dp_toolbox_duplicate&post=' . $post->ID ),
        'dp_toolbox_duplicate_' . $post->ID
    );

    $actions['dp_duplicate'] = '<a href="' . esc_url( $url ) . '" title="Dupliceer dit item">Dupliceer</a>';
    return $actions;
}

/**
 * Handle the duplication.
 */
add_action( 'admin_action_dp_toolbox_duplicate', function () {
    if ( ! isset( $_GET['post'] ) || ! isset( $_GET['_wpnonce'] ) ) {
        wp_die( 'Ongeldige aanvraag.' );
    }

    $post_id = absint( $_GET['post'] );
    check_admin_referer( 'dp_toolbox_duplicate_' . $post_id );

    $post = get_post( $post_id );
    if ( ! $post || ! current_user_can( 'edit_posts' ) ) {
        wp_die( 'Je hebt geen toestemming om dit te doen.' );
    }

    // Create duplicate
    $new_post = [
        'post_title'    => $post->post_title . ' (kopie)',
        'post_content'  => $post->post_content,
        'post_excerpt'  => $post->post_excerpt,
        'post_status'   => 'draft',
        'post_type'     => $post->post_type,
        'post_author'   => get_current_user_id(),
        'post_parent'   => $post->post_parent,
        'menu_order'    => $post->menu_order,
        'comment_status' => $post->comment_status,
        'ping_status'   => $post->ping_status,
    ];

    $new_id = wp_insert_post( $new_post );

    if ( is_wp_error( $new_id ) ) {
        wp_die( 'Fout bij het dupliceren.' );
    }

    // Copy post meta
    $meta = get_post_meta( $post_id );
    foreach ( $meta as $key => $values ) {
        if ( $key === '_wp_old_slug' ) continue;
        foreach ( $values as $value ) {
            add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
        }
    }

    // Copy taxonomies
    $taxonomies = get_object_taxonomies( $post->post_type );
    foreach ( $taxonomies as $taxonomy ) {
        $terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $terms ) ) {
            wp_set_object_terms( $new_id, $terms, $taxonomy );
        }
    }

    // Redirect to edit screen of new post
    wp_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
    exit;
} );