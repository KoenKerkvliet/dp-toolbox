<?php
/**
 * Module Name: Media Replacement
 * Description: Vervang mediabestanden met behoud van ID, datum en bestandsnaam.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Enqueue assets on media pages                                      */
/* ------------------------------------------------------------------ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    // Load on media library (grid modal) and attachment edit screen
    if ( ! in_array( $hook, [ 'post.php', 'upload.php' ], true ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    // upload.php = media library grid, post.php with attachment = edit screen
    if ( $hook === 'upload.php' || ( $hook === 'post.php' && $screen->post_type === 'attachment' ) ) {
        wp_enqueue_media();

        $module_url = DP_TOOLBOX_URL . 'modules/media-replacement/assets/';

        wp_enqueue_style(
            'dp-toolbox-media-replace',
            $module_url . 'media-replace.css',
            [],
            DP_TOOLBOX_VERSION
        );

        wp_enqueue_script(
            'dp-toolbox-media-replace',
            $module_url . 'media-replace.js',
            [ 'jquery', 'media-upload' ],
            DP_TOOLBOX_VERSION,
            true
        );

        wp_localize_script( 'dp-toolbox-media-replace', 'dpMR', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dp_toolbox_media_replace' ),
        ] );
    }
} );

/* ------------------------------------------------------------------ */
/*  Add "Replace Media" button to attachment edit fields                */
/* ------------------------------------------------------------------ */

add_filter( 'attachment_fields_to_edit', 'dp_toolbox_mr_add_replace_button', 10, 2 );

function dp_toolbox_mr_add_replace_button( $fields, $post ) {
    if ( ! current_user_can( 'upload_files' ) ) {
        return $fields;
    }

    $mime_type = get_post_mime_type( $post->ID );

    ob_start();
    ?>
    <div class="dp-mr-wrap" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>">
        <button type="button" class="button button-small dp-mr-btn">
            <span class="dashicons dashicons-update"></span>
            Media vervangen
        </button>
        <span class="dp-mr-status"></span>
        <p class="dp-mr-hint">Zelfde bestandstype (<?php echo esc_html( $mime_type ); ?>). ID en links blijven behouden.</p>
    </div>
    <?php
    $html = ob_get_clean();

    $fields['dp_media_replace'] = [
        'label' => 'Vervangen',
        'input' => 'html',
        'html'  => $html,
    ];

    return $fields;
}

/* ------------------------------------------------------------------ */
/*  Modify "Edit" link in media list table                             */
/* ------------------------------------------------------------------ */

add_filter( 'media_row_actions', 'dp_toolbox_mr_modify_row_actions', 10, 2 );

function dp_toolbox_mr_modify_row_actions( $actions, $post ) {
    if ( isset( $actions['edit'] ) && current_user_can( 'upload_files' ) ) {
        $actions['edit'] = str_replace( '>Bewerken<', '>Bewerken / Vervangen<', $actions['edit'] );
        // Fallback for English
        $actions['edit'] = str_replace( '>Edit<', '>Edit / Replace<', $actions['edit'] );
    }
    return $actions;
}

/* ------------------------------------------------------------------ */
/*  AJAX handler for media replacement                                 */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_replace_media', function () {
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }

    check_ajax_referer( 'dp_toolbox_media_replace', 'nonce' );

    $old_id = absint( $_POST['old_attachment_id'] ?? 0 );
    $new_id = absint( $_POST['new_attachment_id'] ?? 0 );

    if ( ! $old_id || ! $new_id ) {
        wp_send_json_error( 'Ongeldige attachment IDs.' );
    }

    $result = dp_toolbox_mr_do_replace( $old_id, $new_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( [
        'message' => 'Media succesvol vervangen.',
        'attachment_id' => $old_id,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Core replacement logic                                             */
/* ------------------------------------------------------------------ */

function dp_toolbox_mr_do_replace( $old_id, $new_id ) {
    // Validate MIME types match
    $old_mime = get_post_mime_type( $old_id );
    $new_mime = get_post_mime_type( $new_id );

    if ( $old_mime !== $new_mime ) {
        return new WP_Error( 'mime_mismatch', sprintf(
            'Bestandstype komt niet overeen: %s → %s',
            $old_mime, $new_mime
        ) );
    }

    // Get new file path
    $new_meta   = wp_get_attachment_metadata( $new_id );
    $upload_dir = wp_upload_dir();

    if ( ! empty( $new_meta['original_image'] ) ) {
        $new_file_path = wp_get_original_image_path( $new_id );
    } else {
        $new_attached_file = get_post_meta( $new_id, '_wp_attached_file', true );
        $new_file_path     = $upload_dir['basedir'] . '/' . $new_attached_file;
    }

    if ( ! $new_file_path || ! file_exists( $new_file_path ) ) {
        return new WP_Error( 'file_missing', 'Nieuw bestand niet gevonden.' );
    }

    // Get old file path
    $old_meta = wp_get_attachment_metadata( $old_id );

    if ( ! empty( $old_meta['original_image'] ) ) {
        $old_file_path = wp_get_original_image_path( $old_id );
    } else {
        $old_attached_file = get_post_meta( $old_id, '_wp_attached_file', true );
        $old_file_path     = $upload_dir['basedir'] . '/' . $old_attached_file;
    }

    // Delete old media files (main + thumbnails)
    dp_toolbox_mr_delete_media_files( $old_id );

    // Ensure target directory exists
    $old_dir = dirname( $old_file_path );
    if ( ! is_dir( $old_dir ) ) {
        wp_mkdir_p( $old_dir );
    }

    // Copy new file to old file location
    if ( ! copy( $new_file_path, $old_file_path ) ) {
        return new WP_Error( 'copy_failed', 'Bestand kon niet gekopieerd worden.' );
    }

    // Regenerate attachment metadata for old attachment
    $new_metadata = wp_generate_attachment_metadata( $old_id, $old_file_path );
    wp_update_attachment_metadata( $old_id, $new_metadata );

    // Delete the temporary new attachment (files + database entries)
    wp_delete_attachment( $new_id, true );

    // Track recently replaced attachments for cache busting
    $replaced = get_option( 'dp_toolbox_mr_replaced', [] );
    $replaced[ $old_id ] = time();

    if ( count( $replaced ) > 10 ) {
        asort( $replaced );
        $replaced = array_slice( $replaced, -10, 10, true );
    }
    update_option( 'dp_toolbox_mr_replaced', $replaced, false );

    return true;
}

/* ------------------------------------------------------------------ */
/*  Delete all files for an attachment (main + thumbnails)             */
/* ------------------------------------------------------------------ */

function dp_toolbox_mr_delete_media_files( $attachment_id ) {
    $meta = wp_get_attachment_metadata( $attachment_id );
    $upload_dir = wp_upload_dir();

    $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
    if ( ! $attached_file ) {
        return;
    }

    $file_path = $upload_dir['basedir'] . '/' . $attached_file;
    $file_dir  = dirname( $file_path );

    // Delete intermediate sizes (thumbnails)
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        foreach ( $meta['sizes'] as $size ) {
            $thumb_path = $file_dir . '/' . $size['file'];
            if ( file_exists( $thumb_path ) ) {
                @unlink( $thumb_path );
            }
        }
    }

    // Delete main file
    if ( file_exists( $file_path ) ) {
        @unlink( $file_path );
    }

    // Delete original image (for large images >2560px)
    if ( ! empty( $meta['original_image'] ) ) {
        $original_path = $file_dir . '/' . $meta['original_image'];
        if ( file_exists( $original_path ) ) {
            @unlink( $original_path );
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Custom success message after replacement                           */
/* ------------------------------------------------------------------ */

add_filter( 'post_updated_messages', 'dp_toolbox_mr_updated_messages' );

function dp_toolbox_mr_updated_messages( $messages ) {
    $messages['attachment'][4] = 'Media bijgewerkt. Gebruik Ctrl+Shift+R (hard refresh) als de preview niet direct verandert.';
    return $messages;
}

/* ------------------------------------------------------------------ */
/*  Cache busting for recently replaced media                          */
/* ------------------------------------------------------------------ */

add_filter( 'wp_get_attachment_image_src', 'dp_toolbox_mr_cache_bust_src', 10, 2 );

function dp_toolbox_mr_cache_bust_src( $image, $attachment_id ) {
    if ( ! is_admin() || ! $image ) {
        return $image;
    }

    $replaced = get_option( 'dp_toolbox_mr_replaced', [] );
    if ( ! isset( $replaced[ $attachment_id ] ) ) {
        return $image;
    }

    $timestamp = $replaced[ $attachment_id ];
    $separator = ( strpos( $image[0], '?' ) !== false ) ? '&' : '?';
    $image[0] .= $separator . 't=' . $timestamp;

    return $image;
}

add_filter( 'wp_calculate_image_srcset', 'dp_toolbox_mr_cache_bust_srcset', 10, 5 );

function dp_toolbox_mr_cache_bust_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
    if ( ! is_admin() ) {
        return $sources;
    }

    $replaced = get_option( 'dp_toolbox_mr_replaced', [] );
    if ( ! isset( $replaced[ $attachment_id ] ) ) {
        return $sources;
    }

    $timestamp = $replaced[ $attachment_id ];
    foreach ( $sources as &$source ) {
        $separator = ( strpos( $source['url'], '?' ) !== false ) ? '&' : '?';
        $source['url'] .= $separator . 't=' . $timestamp;
    }

    return $sources;
}

add_filter( 'wp_prepare_attachment_for_js', 'dp_toolbox_mr_cache_bust_js', 10, 2 );

function dp_toolbox_mr_cache_bust_js( $response, $attachment ) {
    $replaced = get_option( 'dp_toolbox_mr_replaced', [] );
    if ( ! isset( $replaced[ $attachment->ID ] ) ) {
        return $response;
    }

    $timestamp = $replaced[ $attachment->ID ];
    $separator = ( strpos( $response['url'], '?' ) !== false ) ? '&' : '?';
    $response['url'] .= $separator . 't=' . $timestamp;

    if ( ! empty( $response['sizes'] ) ) {
        foreach ( $response['sizes'] as &$size ) {
            $sep = ( strpos( $size['url'], '?' ) !== false ) ? '&' : '?';
            $size['url'] .= $sep . 't=' . $timestamp;
        }
    }

    return $response;
}
