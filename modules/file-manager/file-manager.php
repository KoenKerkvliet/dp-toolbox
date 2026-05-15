<?php
/**
 * Module Name: File Manager
 * Description: Beheer bestanden op de server vanuit WordPress — bekijken, bewerken, uploaden en ZIPs uitpakken.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Security: validate path is within ABSPATH                          */
/* ------------------------------------------------------------------ */

function dp_toolbox_fm_safe_path( $path ) {
    $real = realpath( $path );
    $base = realpath( ABSPATH );

    // For new files that don't exist yet, validate parent directory
    if ( $real === false ) {
        $parent = realpath( dirname( $path ) );
        if ( $parent === false || strpos( $parent, $base ) !== 0 ) {
            return false;
        }
        return $parent . DIRECTORY_SEPARATOR . basename( $path );
    }

    if ( strpos( $real, $base ) !== 0 ) {
        return false;
    }

    return $real;
}

/**
 * Convert absolute path to relative (from ABSPATH).
 */
function dp_toolbox_fm_rel_path( $path ) {
    return ltrim( str_replace( realpath( ABSPATH ), '', $path ), '/\\' );
}

/* ------------------------------------------------------------------ */
/*  Top-level menu registration                                        */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    add_menu_page(
        'DP File Manager',
        'DP File Manager',
        'manage_options',
        'dp-file-manager',
        'dp_toolbox_fm_render_page',
        'dashicons-portfolio',
        4 // Direct after DP Toolbox (3)
    );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: list directory                                               */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_list', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $dir = $_POST['dir'] ?? ABSPATH;
    $safe = dp_toolbox_fm_safe_path( $dir );

    if ( ! $safe || ! is_dir( $safe ) ) {
        wp_send_json_error( 'Ongeldig pad.' );
    }

    $items = [];
    $entries = @scandir( $safe );
    if ( $entries === false ) {
        wp_send_json_error( 'Kan map niet lezen.' );
    }

    foreach ( $entries as $entry ) {
        if ( $entry === '.' ) continue;

        $full = $safe . DIRECTORY_SEPARATOR . $entry;
        $is_dir = is_dir( $full );

        $items[] = [
            'name'     => $entry,
            'path'     => str_replace( '\\', '/', $full ),
            'is_dir'   => $is_dir,
            'size'     => $is_dir ? null : filesize( $full ),
            'modified' => date( 'Y-m-d H:i', filemtime( $full ) ),
            'writable' => is_writable( $full ),
            'ext'      => $is_dir ? '' : strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ),
        ];
    }

    // Sort: ".." first, then directories, then files
    usort( $items, function ( $a, $b ) {
        if ( $a['name'] === '..' ) return -1;
        if ( $b['name'] === '..' ) return 1;
        if ( $a['is_dir'] !== $b['is_dir'] ) return $a['is_dir'] ? -1 : 1;
        return strcasecmp( $a['name'], $b['name'] );
    } );

    wp_send_json_success( [
        'dir'   => str_replace( '\\', '/', $safe ),
        'rel'   => dp_toolbox_fm_rel_path( $safe ),
        'items' => $items,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: read file                                                    */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_read', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $file = $_POST['file'] ?? '';
    $safe = dp_toolbox_fm_safe_path( $file );

    if ( ! $safe || ! is_file( $safe ) ) {
        wp_send_json_error( 'Bestand niet gevonden.' );
    }

    if ( ! is_readable( $safe ) ) {
        wp_send_json_error( 'Bestand is niet leesbaar.' );
    }

    $size = filesize( $safe );
    if ( $size > 2 * 1024 * 1024 ) { // 2MB limit
        wp_send_json_error( 'Bestand is te groot om te bewerken (max 2MB).' );
    }

    $content = file_get_contents( $safe );

    // Check if binary
    if ( preg_match( '/[\x00-\x08\x0E-\x1F]/', substr( $content, 0, 8192 ) ) ) {
        wp_send_json_error( 'Binair bestand — kan niet bewerkt worden.' );
    }

    wp_send_json_success( [
        'name'    => basename( $safe ),
        'path'    => str_replace( '\\', '/', $safe ),
        'content' => $content,
        'size'    => $size,
        'ext'     => strtolower( pathinfo( $safe, PATHINFO_EXTENSION ) ),
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: save file                                                    */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $file    = $_POST['file'] ?? '';
    $content = wp_unslash( $_POST['content'] ?? '' );
    $safe    = dp_toolbox_fm_safe_path( $file );

    if ( ! $safe ) {
        wp_send_json_error( 'Ongeldig pad.' );
    }

    if ( is_file( $safe ) && ! is_writable( $safe ) ) {
        wp_send_json_error( 'Bestand is niet schrijfbaar.' );
    }

    $result = file_put_contents( $safe, $content );
    if ( $result === false ) {
        wp_send_json_error( 'Opslaan mislukt.' );
    }

    wp_send_json_success( [ 'message' => 'Bestand opgeslagen.', 'size' => $result ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: create file or folder                                        */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_create', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $dir  = $_POST['dir'] ?? '';
    $name = sanitize_file_name( $_POST['name'] ?? '' );
    $type = $_POST['type'] ?? 'file'; // 'file' or 'folder'

    if ( empty( $name ) ) {
        wp_send_json_error( 'Naam is verplicht.' );
    }

    $safe_dir = dp_toolbox_fm_safe_path( $dir );
    if ( ! $safe_dir || ! is_dir( $safe_dir ) ) {
        wp_send_json_error( 'Ongeldig pad.' );
    }

    $target = $safe_dir . DIRECTORY_SEPARATOR . $name;

    if ( file_exists( $target ) ) {
        wp_send_json_error( 'Er bestaat al een bestand/map met deze naam.' );
    }

    if ( $type === 'folder' ) {
        if ( ! mkdir( $target, 0755 ) ) {
            wp_send_json_error( 'Map aanmaken mislukt.' );
        }
    } else {
        if ( file_put_contents( $target, '' ) === false ) {
            wp_send_json_error( 'Bestand aanmaken mislukt.' );
        }
    }

    wp_send_json_success( [ 'message' => ucfirst( $type ) . ' aangemaakt.' ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: delete file or folder                                        */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_delete', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $path = $_POST['path'] ?? '';
    $safe = dp_toolbox_fm_safe_path( $path );

    if ( ! $safe ) {
        wp_send_json_error( 'Ongeldig pad.' );
    }

    // Protect critical paths
    $protected = [
        realpath( ABSPATH ),
        realpath( ABSPATH . 'wp-admin' ),
        realpath( ABSPATH . 'wp-includes' ),
        realpath( ABSPATH . 'wp-config.php' ),
    ];
    if ( in_array( $safe, array_filter( $protected ), true ) ) {
        wp_send_json_error( 'Dit pad is beschermd en kan niet verwijderd worden.' );
    }

    if ( is_dir( $safe ) ) {
        // Recursive delete
        $it    = new RecursiveDirectoryIterator( $safe, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                rmdir( $file->getRealPath() );
            } else {
                unlink( $file->getRealPath() );
            }
        }
        if ( ! rmdir( $safe ) ) {
            wp_send_json_error( 'Map verwijderen mislukt.' );
        }
    } else {
        if ( ! unlink( $safe ) ) {
            wp_send_json_error( 'Bestand verwijderen mislukt.' );
        }
    }

    wp_send_json_success( [ 'message' => 'Verwijderd.' ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: rename                                                       */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_rename', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $path     = $_POST['path'] ?? '';
    $new_name = sanitize_file_name( $_POST['new_name'] ?? '' );
    $safe     = dp_toolbox_fm_safe_path( $path );

    if ( ! $safe || empty( $new_name ) ) {
        wp_send_json_error( 'Ongeldige invoer.' );
    }

    $new_path = dirname( $safe ) . DIRECTORY_SEPARATOR . $new_name;

    if ( file_exists( $new_path ) ) {
        wp_send_json_error( 'Er bestaat al een item met deze naam.' );
    }

    if ( ! rename( $safe, $new_path ) ) {
        wp_send_json_error( 'Hernoemen mislukt.' );
    }

    wp_send_json_success( [ 'message' => 'Hernoemd.' ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: upload file                                                  */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_upload', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $dir = $_POST['dir'] ?? '';
    $safe_dir = dp_toolbox_fm_safe_path( $dir );

    if ( ! $safe_dir || ! is_dir( $safe_dir ) ) {
        wp_send_json_error( 'Ongeldig pad.' );
    }

    if ( empty( $_FILES['file'] ) ) {
        wp_send_json_error( 'Geen bestand ontvangen.' );
    }

    $file = $_FILES['file'];
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'Upload fout (code ' . $file['error'] . ').' );
    }

    $target = $safe_dir . DIRECTORY_SEPARATOR . sanitize_file_name( $file['name'] );

    if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
        wp_send_json_error( 'Bestand verplaatsen mislukt.' );
    }

    wp_send_json_success( [ 'message' => 'Bestand geüpload.' ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: unzip archive in-place                                       */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_unzip', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_file_manager', 'nonce' );

    $path = $_POST['path'] ?? '';
    $safe = dp_toolbox_fm_safe_path( $path );

    if ( ! $safe || ! is_file( $safe ) ) {
        wp_send_json_error( 'Bestand niet gevonden.' );
    }
    if ( strtolower( pathinfo( $safe, PATHINFO_EXTENSION ) ) !== 'zip' ) {
        wp_send_json_error( 'Alleen .zip-bestanden kunnen uitgepakt worden.' );
    }
    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_send_json_error( 'ZipArchive is niet beschikbaar op deze server.' );
    }

    $dest = dirname( $safe );
    $zip  = new ZipArchive();
    if ( $zip->open( $safe ) !== true ) {
        wp_send_json_error( 'Kon ZIP niet openen.' );
    }

    // Zip-slip protection: reject entries with .. or absolute paths
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( $name === false ) continue;
        // Reject directory traversal, absolute paths, drive letters
        if ( strpos( $name, '..' ) !== false || strpos( $name, ':' ) !== false || strpos( $name, "\0" ) !== false || $name[0] === '/' || $name[0] === '\\' ) {
            $zip->close();
            wp_send_json_error( 'ZIP bevat onveilige paden en wordt geweigerd.' );
        }
    }

    // Detect top-level entries (for overwrite handling + reporting)
    $top_level_dirs  = [];
    $top_level_files = [];
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( $name === false ) continue;
        $slash_pos = strpos( $name, '/' );
        if ( $slash_pos !== false ) {
            $top_level_dirs[ substr( $name, 0, $slash_pos ) ] = true;
        } elseif ( $name !== '' ) {
            $top_level_files[ $name ] = true;
        }
    }

    // Remove existing top-level dirs first so extract is a clean overwrite (mimics WP plugin install behavior)
    foreach ( array_keys( $top_level_dirs ) as $top ) {
        $target_path = $dest . DIRECTORY_SEPARATOR . $top;
        if ( ! is_dir( $target_path ) ) continue;
        $it    = new RecursiveDirectoryIterator( $target_path, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $target_path );
    }

    if ( ! $zip->extractTo( $dest ) ) {
        $zip->close();
        wp_send_json_error( 'Uitpakken mislukt.' );
    }
    $zip->close();

    $dir_count  = count( $top_level_dirs );
    $file_count = count( $top_level_files );
    $parts = [];
    if ( $dir_count > 0 )  $parts[] = $dir_count  . ' ' . ( $dir_count  === 1 ? 'map'      : 'mappen' );
    if ( $file_count > 0 ) $parts[] = $file_count . ' ' . ( $file_count === 1 ? 'bestand'  : 'bestanden' );
    $summary = empty( $parts ) ? 'leeg archief' : implode( ' + ', $parts );

    wp_send_json_success( [
        'message' => 'Uitgepakt: ' . $summary . '.',
        'top_level_dirs'  => array_keys( $top_level_dirs ),
        'top_level_files' => array_keys( $top_level_files ),
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: download file                                                */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_fm_download', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toestemming.' );
    }

    check_admin_referer( 'dp_toolbox_fm_download', 'nonce' );

    $file = $_GET['file'] ?? '';
    $safe = dp_toolbox_fm_safe_path( $file );

    if ( ! $safe || ! is_file( $safe ) ) {
        wp_die( 'Bestand niet gevonden.' );
    }

    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: attachment; filename="' . basename( $safe ) . '"' );
    header( 'Content-Length: ' . filesize( $safe ) );
    readfile( $safe );
    exit;
} );

/* ------------------------------------------------------------------ */
/*  Render page                                                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_fm_render_page() {
    require_once __DIR__ . '/admin-page.php';
}
