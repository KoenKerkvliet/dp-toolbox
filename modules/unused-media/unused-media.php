<?php
/**
 * Module Name: Unused Media Finder
 * Description: Vindt afbeeldingen in de mediabibliotheek die nergens op de site gebruikt worden.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  AJAX: scan for unused media (batched)                              */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_scan_unused_media', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    check_ajax_referer( 'dp_toolbox_unused_media', 'nonce' );

    global $wpdb;

    $offset   = absint( $_POST['offset'] ?? 0 );
    $per_page = 50;

    // Get batch of image attachments
    $attachments = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, guid, post_title FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_mime_type LIKE 'image/%%'
         ORDER BY ID ASC
         LIMIT %d OFFSET %d",
        $per_page, $offset
    ) );

    // Total count for progress
    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_mime_type LIKE 'image/%%'"
    );

    if ( empty( $attachments ) ) {
        wp_send_json_success( [
            'complete' => true,
            'total'    => $total,
        ] );
    }

    $unused = [];

    foreach ( $attachments as $att ) {
        $id       = (int) $att->ID;
        $filename = basename( get_attached_file( $id ) );
        $basename = pathinfo( $filename, PATHINFO_FILENAME );

        // 1. Used as featured image?
        $as_thumb = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value = %s", $id
        ) );
        if ( $as_thumb > 0 ) continue;

        // 2. Referenced in any post_content?
        $in_content = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','private','pending')
             AND post_type NOT IN ('attachment','revision','nav_menu_item')
             AND post_content LIKE %s",
            '%' . $wpdb->esc_like( $basename ) . '%'
        ) );
        if ( $in_content > 0 ) continue;

        // 3. Used in any postmeta value?
        $in_meta = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key != '_thumbnail_id'
             AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like( $basename ) . '%',
            '%"' . $wpdb->esc_like( (string) $id ) . '"%'
        ) );
        if ( $in_meta > 0 ) continue;

        // 4. Used in options (widgets, theme mods, site logo, etc.)?
        $in_options = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_value LIKE %s",
            '%' . $wpdb->esc_like( $basename ) . '%'
        ) );
        if ( $in_options > 0 ) continue;

        // 5. Check site icon
        if ( $id === (int) get_option( 'site_icon' ) ) continue;

        // If we got here, the image is unused
        $file_path = get_attached_file( $id );
        $file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;
        $thumb_url = wp_get_attachment_image_url( $id, 'thumbnail' );

        $unused[] = [
            'id'        => $id,
            'title'     => $att->post_title,
            'filename'  => $filename,
            'size'      => $file_size,
            'thumb'     => $thumb_url ?: '',
            'edit_url'  => get_edit_post_link( $id, 'raw' ),
        ];
    }

    wp_send_json_success( [
        'complete' => false,
        'offset'   => $offset + $per_page,
        'total'    => $total,
        'scanned'  => min( $offset + $per_page, $total ),
        'unused'   => $unused,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: delete selected unused media                                 */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_delete_unused_media', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    check_ajax_referer( 'dp_toolbox_unused_media', 'nonce' );

    $ids     = array_map( 'absint', $_POST['ids'] ?? [] );
    $deleted = 0;
    $freed   = 0;

    foreach ( $ids as $id ) {
        $file_path = get_attached_file( $id );
        $file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;

        if ( wp_delete_attachment( $id, true ) ) {
            $deleted++;
            $freed += $file_size;
        }
    }

    wp_send_json_success( [
        'deleted' => $deleted,
        'freed'   => $freed,
    ] );
} );

/* Admin page */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}