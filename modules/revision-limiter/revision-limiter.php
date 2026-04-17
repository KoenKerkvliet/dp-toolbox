<?php
/**
 * Module Name: Revision Limiter
 * Description: Beperk het aantal revisies per bericht om de database schoon te houden.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Limit the number of revisions stored per post.
 */
add_filter( 'wp_revisions_to_keep', function ( $num, $post ) {
    $limit = (int) get_option( 'dp_toolbox_revision_limit', 5 );
    return $limit;
}, 10, 2 );

/**
 * AJAX: cleanup old revisions above the limit.
 */
add_action( 'wp_ajax_dp_toolbox_cleanup_revisions', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'dp_toolbox_revisions', 'nonce' );

    $limit = absint( $_POST['limit'] ?? 5 );
    global $wpdb;

    // Get all posts that have revisions
    $parents = $wpdb->get_col(
        "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0"
    );

    $deleted = 0;
    foreach ( $parents as $parent_id ) {
        $revisions = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
            $parent_id
        ) );

        $to_delete = array_slice( $revisions, $limit );
        foreach ( $to_delete as $rev_id ) {
            wp_delete_post_revision( $rev_id );
            $deleted++;
        }
    }

    wp_send_json_success( [ 'deleted' => $deleted ] );
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
