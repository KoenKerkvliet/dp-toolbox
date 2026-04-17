<?php
/**
 * Module Name: WebP Converter
 * Description: Converteert afbeeldingen automatisch naar WebP, met bulk-conversie en cleanup.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function dp_toolbox_format_bytes( $bytes, $precision = 2 ) {
    $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
    $bytes = max( $bytes, 0 );
    $pow   = ( $bytes > 0 ) ? floor( log( $bytes ) / log( 1024 ) ) : 0;
    $pow   = min( $pow, count( $units ) - 1 );
    $bytes /= pow( 1024, $pow );
    return round( $bytes, $precision ) . ' ' . $units[ $pow ];
}

function dp_toolbox_get_max_width() {
    return (int) get_option( 'dp_toolbox_webp_max_width', 1920 );
}

/* ------------------------------------------------------------------ */
/*  Limit intermediate image sizes to thumbnail only                   */
/* ------------------------------------------------------------------ */

add_filter( 'intermediate_image_sizes_advanced', function ( $sizes ) {
    return [ 'thumbnail' => $sizes['thumbnail'] ];
} );

add_action( 'admin_init', function () {
    update_option( 'thumbnail_size_w', 150 );
    update_option( 'thumbnail_size_h', 150 );
    update_option( 'thumbnail_crop', 1 );
} );

/* ------------------------------------------------------------------ */
/*  Convert new uploads to WebP                                        */
/* ------------------------------------------------------------------ */

add_filter( 'wp_handle_upload', function ( $upload ) {
    $supported_types      = [ 'image/jpeg', 'image/png' ];
    $file_extension       = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
    $allowed_extensions   = [ 'jpg', 'jpeg', 'png', 'webp' ];

    if ( ! in_array( $file_extension, $allowed_extensions )
         || ! in_array( $upload['type'], $supported_types )
         || ! ( extension_loaded( 'imagick' ) || extension_loaded( 'gd' ) ) ) {
        return $upload;
    }

    $file_path    = $upload['file'];
    $image_editor = wp_get_image_editor( $file_path );
    if ( is_wp_error( $image_editor ) ) {
        return $upload;
    }

    $max_width  = dp_toolbox_get_max_width();
    $dimensions = $image_editor->get_size();
    if ( $dimensions['width'] > $max_width ) {
        $image_editor->resize( $max_width, null, false );
    }

    $file_info     = pathinfo( $file_path );
    $new_file_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';

    $saved_image = $image_editor->save( $new_file_path, 'image/webp', [ 'quality' => 80 ] );
    if ( ! is_wp_error( $saved_image ) && file_exists( $saved_image['path'] ) ) {
        $upload['file'] = $saved_image['path'];
        $upload['url']  = str_replace( basename( $upload['url'] ), basename( $saved_image['path'] ), $upload['url'] );
        $upload['type'] = 'image/webp';

        if ( file_exists( $file_path ) ) {
            $attempts = 0;
            while ( $attempts < 5 && file_exists( $file_path ) ) {
                chmod( $file_path, 0644 );
                if ( unlink( $file_path ) ) {
                    break;
                }
                $attempts++;
                sleep( 1 );
            }
        }
    }

    return $upload;
}, 10, 1 );

/* ------------------------------------------------------------------ */
/*  Fix WebP metadata & thumbnail                                      */
/* ------------------------------------------------------------------ */

add_filter( 'wp_generate_attachment_metadata', function ( $metadata, $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    if ( pathinfo( $file, PATHINFO_EXTENSION ) !== 'webp' ) {
        return $metadata;
    }

    $uploads   = wp_upload_dir();
    $dirname   = dirname( $file );
    $base_name = pathinfo( basename( $file ), PATHINFO_FILENAME );

    $metadata['file']      = str_replace( $uploads['basedir'] . '/', '', $file );
    $metadata['mime_type'] = 'image/webp';

    if ( ! isset( $metadata['sizes']['thumbnail'] )
         || ! file_exists( $uploads['basedir'] . '/' . $metadata['sizes']['thumbnail']['file'] ) ) {
        $editor = wp_get_image_editor( $file );
        if ( ! is_wp_error( $editor ) ) {
            $editor->resize( 150, 150, true );
            $thumbnail_path = $dirname . '/' . $base_name . '-150x150.webp';
            $saved          = $editor->save( $thumbnail_path, 'image/webp' );
            if ( ! is_wp_error( $saved ) && file_exists( $saved['path'] ) ) {
                $metadata['sizes']['thumbnail'] = [
                    'file'      => basename( $thumbnail_path ),
                    'width'     => 150,
                    'height'    => 150,
                    'mime-type' => 'image/webp',
                ];
            }
        }
    }

    return $metadata;
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  AJAX: convert single image (bulk)                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
    add_action( 'wp_ajax_dp_toolbox_webp_status',         'dp_toolbox_webp_conversion_status' );
    add_action( 'wp_ajax_dp_toolbox_webp_convert_single', 'dp_toolbox_convert_single_image' );
    add_action( 'wp_ajax_dp_toolbox_convert_post_images', 'dp_toolbox_convert_post_images_to_webp' );

    if ( isset( $_GET['convert_existing_images_to_webp'] ) && current_user_can( 'manage_options' ) ) {
        delete_option( 'dp_toolbox_webp_conversion_complete' );
    }
} );

function dp_toolbox_convert_single_image() {
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['offset'] ) ) {
        wp_send_json_error( 'Permission denied or invalid offset' );
    }

    $offset = absint( $_POST['offset'] );
    wp_raise_memory_limit( 'image' );
    set_time_limit( 30 );

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp' ],
        'posts_per_page' => 1,
        'offset'         => $offset,
        'fields'         => 'ids',
    ] );

    $log = get_option( 'dp_toolbox_webp_conversion_log', [] );

    if ( empty( $attachments ) ) {
        update_option( 'dp_toolbox_webp_conversion_complete', true );
        $log[] = "<strong style='color:#281E5D;text-transform:uppercase;letter-spacing:1px;'>Conversion complete</strong>: No more images to process";
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'complete' => true ] );
    }

    $attachment_id = $attachments[0];
    $max_width     = dp_toolbox_get_max_width();
    $file_path     = get_attached_file( $attachment_id );
    $base_file     = basename( $file_path );

    if ( ! file_exists( $file_path ) ) {
        $log[] = "Skipped (not found): $base_file";
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'complete' => false, 'offset' => $offset + 1 ] );
    }

    if ( ! ( extension_loaded( 'imagick' ) || extension_loaded( 'gd' ) ) ) {
        $log[] = "Error (no image library): $base_file";
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'complete' => false, 'offset' => $offset + 1 ] );
    }

    $editor = wp_get_image_editor( $file_path );
    if ( is_wp_error( $editor ) ) {
        $log[] = "Error (editor failed): $base_file - " . $editor->get_error_message();
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'complete' => false, 'offset' => $offset + 1 ] );
    }

    $dimensions = $editor->get_size();
    if ( strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) === 'webp' && $dimensions['width'] <= $max_width ) {
        $log[] = "Skipped (WebP and within size): $base_file";
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'complete' => false, 'offset' => $offset + 1 ] );
    }

    $resized = false;
    if ( $dimensions['width'] > $max_width ) {
        $editor->resize( $max_width, null, false );
        $resized = true;
    }

    $path_info     = pathinfo( $file_path );
    $new_file_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
    $result        = $editor->save( $new_file_path, 'image/webp' );

    if ( is_wp_error( $result ) ) {
        $log[] = "Error (conversion failed): $base_file - " . $result->get_error_message();
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'complete' => false, 'offset' => $offset + 1 ] );
    }

    update_attached_file( $attachment_id, $new_file_path );
    wp_update_post( [ 'ID' => $attachment_id, 'post_mime_type' => 'image/webp' ] );
    $metadata = wp_generate_attachment_metadata( $attachment_id, $new_file_path );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    if ( $file_path !== $new_file_path && file_exists( $file_path ) ) {
        $attempts = 0;
        while ( $attempts < 5 && file_exists( $file_path ) ) {
            chmod( $file_path, 0644 );
            if ( unlink( $file_path ) ) {
                $log[] = "Deleted original: $base_file";
                break;
            }
            $attempts++;
            sleep( 1 );
        }
        if ( file_exists( $file_path ) ) {
            $log[] = "Error (failed to delete original after 5 retries): $base_file";
        }
    }

    $log[] = "Converted: $base_file -> " . basename( $new_file_path ) . ( $resized ? " (resized from {$dimensions['width']}px to {$max_width}px)" : '' );
    update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
    wp_send_json_success( [ 'complete' => false, 'offset' => $offset + 1 ] );
}

/* ------------------------------------------------------------------ */
/*  AJAX: conversion status                                            */
/* ------------------------------------------------------------------ */

function dp_toolbox_webp_conversion_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $total     = wp_count_posts( 'attachment' )->inherit;
    $converted = count( get_posts( [
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_mime_type' => 'image/webp',
    ] ) );
    $remaining = count( get_posts( [
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_mime_type' => [ 'image/jpeg', 'image/png' ],
    ] ) );

    wp_send_json( [
        'total'      => $total,
        'converted'  => $converted,
        'remaining'  => $remaining,
        'percentage' => $total ? round( ( $converted / $total ) * 100, 2 ) : 100,
        'log'        => get_option( 'dp_toolbox_webp_conversion_log', [] ),
        'complete'   => get_option( 'dp_toolbox_webp_conversion_complete', false ),
        'max_width'  => dp_toolbox_get_max_width(),
    ] );
}

/* ------------------------------------------------------------------ */
/*  AJAX: convert post image URLs to WebP                              */
/* ------------------------------------------------------------------ */

function dp_toolbox_convert_post_images_to_webp() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $log = get_option( 'dp_toolbox_webp_conversion_log', [] );
    $log[] = '[' . date( 'Y-m-d H:i:s' ) . '] Starting conversion of post images to WebP...';

    $posts = get_posts( [
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    if ( ! $posts ) {
        $log[] = 'No posts found.';
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        wp_send_json_success( [ 'message' => 'No posts found' ] );
    }

    $updated_count  = 0;
    $checked_images = 0;

    foreach ( $posts as $post_id ) {
        $content          = get_post_field( 'post_content', $post_id );
        $original_content = $content;

        $content = preg_replace_callback(
            '/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
            function ( $matches ) use ( &$checked_images, &$log ) {
                $original_url = $matches[1];
                $checked_images++;

                $webp_url  = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $original_url );
                $webp_path = str_replace( site_url(), ABSPATH, $webp_url );

                if ( file_exists( $webp_path ) ) {
                    $log[] = "Replacing: $original_url -> $webp_url";
                    return str_replace( $original_url, $webp_url, $matches[0] );
                }

                return $matches[0];
            },
            $content
        );

        if ( $content !== $original_content ) {
            wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
            $updated_count++;
        }
    }

    $log[] = "Checked $checked_images images. Updated $updated_count posts.";
    $log[] = 'Conversion process completed.';
    update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );

    wp_send_json_success( [ 'message' => "Checked $checked_images images. Updated $updated_count posts." ] );
}

/* ------------------------------------------------------------------ */
/*  Cleanup leftover originals & intermediate sizes                    */
/* ------------------------------------------------------------------ */

function dp_toolbox_cleanup_leftover_originals() {
    if ( ! isset( $_GET['cleanup_leftover_originals'] ) || ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    $log         = get_option( 'dp_toolbox_webp_conversion_log', [] );
    $uploads_dir = wp_upload_dir()['basedir'];
    $files       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads_dir ) );
    $deleted     = 0;
    $failed      = 0;

    foreach ( $files as $file ) {
        if ( $file->isDir() ) continue;

        $file_path = $file->getPathname();
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, [ 'webp', 'jpg', 'jpeg', 'png' ] ) ) {
            continue;
        }

        $base_name        = pathinfo( $file_path, PATHINFO_FILENAME );
        $thumbnail_pattern = '-150x150';
        $size_pattern      = '/-\d+x\d+\.(webp|jpg|jpeg|png)$/i';

        if ( $extension === 'webp' ) {
            if ( strpos( $base_name, $thumbnail_pattern ) !== false ) continue;
            if ( ! preg_match( $size_pattern, $file_path ) ) continue;
        }

        $attempts = 0;
        while ( $attempts < 5 && file_exists( $file_path ) ) {
            chmod( $file_path, 0644 );
            if ( unlink( $file_path ) ) {
                $log[] = 'Cleanup: Deleted file: ' . basename( $file_path );
                $deleted++;
                update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
                break;
            }
            $attempts++;
            sleep( 1 );
        }
        if ( file_exists( $file_path ) ) {
            $log[] = 'Cleanup: Failed to delete file: ' . basename( $file_path );
            $failed++;
            update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        }
    }

    $log[] = "<strong style='color:#281E5D;text-transform:uppercase;letter-spacing:1px;'>Cleanup Complete</strong> Deleted $deleted files, $failed failed";
    update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );

    // Regenerate thumbnails for all WebP attachments
    $webp_attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image/webp',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $webp_attachments as $attachment_id ) {
        $fp = get_attached_file( $attachment_id );
        if ( file_exists( $fp ) ) {
            $meta = wp_generate_attachment_metadata( $attachment_id, $fp );
            if ( ! is_wp_error( $meta ) ) {
                wp_update_attachment_metadata( $attachment_id, $meta );
                $log[] = 'Regenerated thumbnail for: ' . basename( $fp );
            } else {
                $log[] = 'Failed to regenerate thumbnail for: ' . basename( $fp );
            }
            update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        }
    }

    $log[] = "<strong style='color:#281E5D;text-transform:uppercase;letter-spacing:1px;'>Thumbnail regeneration complete</strong>";
    update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
    return true;
}

/* ------------------------------------------------------------------ */
/*  Helper: set max width via GET                                      */
/* ------------------------------------------------------------------ */

function dp_toolbox_set_max_width() {
    if ( ! isset( $_GET['set_max_width'] ) || ! current_user_can( 'manage_options' ) || ! isset( $_GET['max_width'] ) ) {
        return false;
    }
    $max_width = absint( $_GET['max_width'] );
    if ( $max_width > 0 ) {
        update_option( 'dp_toolbox_webp_max_width', $max_width );
        $log   = get_option( 'dp_toolbox_webp_conversion_log', [] );
        $log[] = "Max width set to: {$max_width}px";
        update_option( 'dp_toolbox_webp_conversion_log', array_slice( $log, -100 ) );
        return true;
    }
    return false;
}

/* ------------------------------------------------------------------ */
/*  Helper: clear log via GET                                          */
/* ------------------------------------------------------------------ */

function dp_toolbox_clear_log() {
    if ( ! isset( $_GET['clear_log'] ) || ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    update_option( 'dp_toolbox_webp_conversion_log', [ 'Log cleared' ] );
    return true;
}

/* Admin page */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
