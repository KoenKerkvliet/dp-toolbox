<?php
/**
 * Module Name: Alt Text Filler
 * Description: Vindt afbeeldingen zonder alt-tekst en genereert suggesties op basis van de bestandsnaam.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generate a human-readable alt text from a filename.
 * E.g. "my-cool-photo_2024" -> "My cool photo 2024"
 */
function dp_toolbox_alt_from_filename( $filename ) {
    // Remove extension
    $name = pathinfo( $filename, PATHINFO_FILENAME );
    // Remove common size suffixes like -150x150, -1024x768
    $name = preg_replace( '/-\d+x\d+$/', '', $name );
    // Replace separators with spaces
    $name = str_replace( [ '-', '_', '.', '+' ], ' ', $name );
    // Remove excessive spaces
    $name = preg_replace( '/\s+/', ' ', trim( $name ) );
    // Capitalize first letter
    $name = mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
    return $name;
}

/* ------------------------------------------------------------------ */
/*  AJAX: get images missing alt text                                  */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_scan_missing_alt', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    check_ajax_referer( 'dp_toolbox_alt_filler', 'nonce' );

    global $wpdb;

    // Find image attachments that either have no _wp_attachment_image_alt meta,
    // or where the value is empty
    $results = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.guid
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
             ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
         WHERE p.post_type = 'attachment'
         AND p.post_mime_type LIKE 'image/%'
         AND (pm.meta_value IS NULL OR pm.meta_value = '')
         ORDER BY p.ID DESC"
    );

    $items = [];
    foreach ( $results as $row ) {
        $file_path = get_attached_file( $row->ID );
        $filename  = basename( $file_path );
        $thumb_url = wp_get_attachment_image_url( $row->ID, 'thumbnail' );

        // Generate suggestion: prefer post_title if it's not a raw slug
        $title_clean = str_replace( [ '-', '_' ], ' ', $row->post_title );
        $file_suggest = dp_toolbox_alt_from_filename( $filename );

        // Use post_title if it looks manually set (different from filename-based slug)
        $slug_from_file = sanitize_title( pathinfo( $filename, PATHINFO_FILENAME ) );
        $suggestion = ( sanitize_title( $row->post_title ) === $slug_from_file )
            ? $file_suggest
            : ucfirst( $title_clean );

        $items[] = [
            'id'         => (int) $row->ID,
            'filename'   => $filename,
            'thumb'      => $thumb_url ?: '',
            'suggestion' => $suggestion,
            'edit_url'   => get_edit_post_link( $row->ID, 'raw' ),
        ];
    }

    wp_send_json_success( [
        'total' => count( $items ),
        'items' => $items,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: save alt texts                                               */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_save_alt_texts', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    check_ajax_referer( 'dp_toolbox_alt_filler', 'nonce' );

    $data  = $_POST['alts'] ?? [];
    $saved = 0;

    if ( is_array( $data ) ) {
        foreach ( $data as $entry ) {
            $id  = absint( $entry['id'] ?? 0 );
            $alt = sanitize_text_field( $entry['alt'] ?? '' );
            if ( $id && $alt !== '' ) {
                update_post_meta( $id, '_wp_attachment_image_alt', $alt );
                $saved++;
            }
        }
    }

    wp_send_json_success( [ 'saved' => $saved ] );
} );

/* Admin page */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}