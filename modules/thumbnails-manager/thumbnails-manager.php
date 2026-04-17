<?php
/**
 * Module Name: Thumbnails Manager
 * Description: Bekijk, beheer en regenereer WordPress thumbnail-formaten.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Get all registered image sizes with details                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_tm_get_all_sizes() {
    $sizes = [];

    // Built-in sizes
    $builtin = [ 'thumbnail', 'medium', 'medium_large', 'large' ];
    foreach ( $builtin as $name ) {
        $sizes[ $name ] = [
            'width'  => (int) get_option( "{$name}_size_w", 0 ),
            'height' => (int) get_option( "{$name}_size_h", 0 ),
            'crop'   => (bool) get_option( "{$name}_crop", false ),
            'source' => 'WordPress',
        ];
    }

    // Additional sizes registered by themes/plugins
    $additional = wp_get_additional_image_sizes();
    foreach ( $additional as $name => $data ) {
        $sizes[ $name ] = [
            'width'  => (int) $data['width'],
            'height' => (int) $data['height'],
            'crop'   => (bool) $data['crop'],
            'source' => 'Thema / Plugin',
        ];
    }

    // Always include the full/original pseudo-size
    $sizes['full'] = [
        'width'  => 0,
        'height' => 0,
        'crop'   => false,
        'source' => 'Origineel',
    ];

    return $sizes;
}

/* ------------------------------------------------------------------ */
/*  AJAX: regenerate thumbnails (batch)                                */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_tm_regenerate', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_thumbnails', 'nonce' );

    $offset = absint( $_POST['offset'] ?? 0 );
    $batch  = absint( $_POST['batch'] ?? 5 );
    $sizes  = $_POST['sizes'] ?? [];

    // Get images
    $args = [
        'post_type'      => 'attachment',
        'post_mime_type'  => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch,
        'offset'         => $offset,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    $query  = new WP_Query( $args );
    $ids    = $query->posts;
    $total  = $query->found_posts;
    $processed = 0;
    $errors = [];

    foreach ( $ids as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            $errors[] = "ID {$id}: bestand niet gevonden";
            continue;
        }

        // Regenerate metadata (this recreates all thumbnails)
        $metadata = wp_generate_attachment_metadata( $id, $file );

        if ( is_wp_error( $metadata ) ) {
            $errors[] = "ID {$id}: " . $metadata->get_error_message();
            continue;
        }

        wp_update_attachment_metadata( $id, $metadata );
        $processed++;
    }

    $new_offset = $offset + count( $ids );

    wp_send_json_success( [
        'processed' => $processed,
        'errors'    => $errors,
        'offset'    => $new_offset,
        'total'     => $total,
        'done'      => $new_offset >= $total,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: count thumbnail files on disk                                */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_tm_stats', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_thumbnails', 'nonce' );

    global $wpdb;

    $total_images = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
    );

    // Count total thumbnail files
    $total_thumbs = 0;
    $total_disk   = 0;

    $images = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
    );

    foreach ( $images as $id ) {
        $meta = wp_get_attachment_metadata( $id );
        if ( ! empty( $meta['sizes'] ) ) {
            $total_thumbs += count( $meta['sizes'] );
            $upload_dir = wp_upload_dir();
            $base_dir   = dirname( $upload_dir['basedir'] . '/' . ( $meta['file'] ?? '' ) );

            foreach ( $meta['sizes'] as $size ) {
                $path = $base_dir . '/' . $size['file'];
                if ( file_exists( $path ) ) {
                    $total_disk += filesize( $path );
                }
            }
        }
    }

    wp_send_json_success( [
        'total_images' => $total_images,
        'total_thumbs' => $total_thumbs,
        'disk_usage'   => size_format( $total_disk ),
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
