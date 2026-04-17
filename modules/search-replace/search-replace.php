<?php
/**
 * Module Name: Search & Replace
 * Description: Zoek en vervang tekst in de WordPress database — serialization-aware en met dry-run.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Get all searchable database tables                                 */
/* ------------------------------------------------------------------ */

function dp_toolbox_sr_get_tables() {
    global $wpdb;

    $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
    return $tables;
}

/* ------------------------------------------------------------------ */
/*  Serialization-aware recursive replace                              */
/* ------------------------------------------------------------------ */

function dp_toolbox_sr_recursive_replace( $search, $replace, $data ) {
    if ( is_string( $data ) ) {
        return str_replace( $search, $replace, $data );
    }

    if ( is_array( $data ) ) {
        $new = [];
        foreach ( $data as $key => $value ) {
            $new[ $key ] = dp_toolbox_sr_recursive_replace( $search, $replace, $value );
        }
        return $new;
    }

    if ( is_object( $data ) ) {
        $props = get_object_vars( $data );
        foreach ( $props as $key => $value ) {
            $data->$key = dp_toolbox_sr_recursive_replace( $search, $replace, $value );
        }
        return $data;
    }

    return $data;
}

/**
 * Process a single database value: unserialize if needed, replace, reserialize.
 * Returns [ 'changed' => bool, 'new_value' => string ]
 */
function dp_toolbox_sr_process_value( $search, $replace, $value ) {
    // Skip if search term not present at all
    if ( strpos( $value, $search ) === false ) {
        return [ 'changed' => false, 'new_value' => $value ];
    }

    // Try to unserialize
    $unserialized = @unserialize( $value );

    if ( $unserialized !== false || $value === 'b:0;' ) {
        // It's serialized data — recursive replace and reserialize
        $new_data  = dp_toolbox_sr_recursive_replace( $search, $replace, $unserialized );
        $new_value = serialize( $new_data );
    } else {
        // Plain string — direct replace
        $new_value = str_replace( $search, $replace, $value );
    }

    return [
        'changed'   => ( $new_value !== $value ),
        'new_value' => $new_value,
    ];
}

/* ------------------------------------------------------------------ */
/*  AJAX: run search & replace (dry-run or live)                       */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_search_replace', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_search_replace', 'nonce' );

    global $wpdb;

    $search  = wp_unslash( $_POST['search'] ?? '' );
    $replace = wp_unslash( $_POST['replace'] ?? '' );
    $tables  = $_POST['tables'] ?? [];
    $dry_run = ! empty( $_POST['dry_run'] );

    if ( empty( $search ) ) {
        wp_send_json_error( 'Zoekterm is verplicht.' );
    }

    if ( $search === $replace ) {
        wp_send_json_error( 'Zoekterm en vervanging zijn identiek.' );
    }

    if ( empty( $tables ) || ! is_array( $tables ) ) {
        wp_send_json_error( 'Selecteer minimaal één tabel.' );
    }

    // Validate tables exist and belong to this WP install
    $valid_tables = dp_toolbox_sr_get_tables();
    $tables = array_intersect( $tables, $valid_tables );

    if ( empty( $tables ) ) {
        wp_send_json_error( 'Geen geldige tabellen geselecteerd.' );
    }

    $total_changes = 0;
    $table_results = [];

    foreach ( $tables as $table ) {
        // Get all columns for this table
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
        $text_columns = [];

        foreach ( $columns as $col ) {
            // Only search text-like columns
            if ( preg_match( '/char|text|varchar|longtext|mediumtext|tinytext/i', $col->Type ) ) {
                $text_columns[] = $col->Field;
            }
        }

        if ( empty( $text_columns ) ) {
            continue;
        }

        // Get primary key
        $primary_key = null;
        foreach ( $columns as $col ) {
            if ( $col->Key === 'PRI' ) {
                $primary_key = $col->Field;
                break;
            }
        }

        if ( ! $primary_key ) {
            continue;
        }

        // Build WHERE clause to only fetch rows containing the search term
        $where_parts = [];
        foreach ( $text_columns as $col ) {
            $where_parts[] = "`{$col}` LIKE '%" . $wpdb->esc_like( $search ) . "%'";
        }
        $where = implode( ' OR ', $where_parts );

        $rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$where}", ARRAY_A );

        $table_changes = 0;

        foreach ( $rows as $row ) {
            $updates = [];

            foreach ( $text_columns as $col ) {
                if ( ! isset( $row[ $col ] ) || strpos( $row[ $col ], $search ) === false ) {
                    continue;
                }

                $result = dp_toolbox_sr_process_value( $search, $replace, $row[ $col ] );

                if ( $result['changed'] ) {
                    $updates[ $col ] = $result['new_value'];
                    $table_changes++;
                }
            }

            if ( ! empty( $updates ) && ! $dry_run ) {
                $wpdb->update(
                    $table,
                    $updates,
                    [ $primary_key => $row[ $primary_key ] ]
                );
            }
        }

        if ( $table_changes > 0 ) {
            $table_results[] = [
                'table'   => $table,
                'changes' => $table_changes,
            ];
            $total_changes += $table_changes;
        }
    }

    // Clear caches after live replace
    if ( ! $dry_run && $total_changes > 0 ) {
        wp_cache_flush();
    }

    wp_send_json_success( [
        'dry_run'       => $dry_run,
        'total_changes' => $total_changes,
        'tables'        => $table_results,
        'message'       => $dry_run
            ? sprintf( '%d wijziging(en) gevonden in %d tabel(len).', $total_changes, count( $table_results ) )
            : sprintf( '%d wijziging(en) doorgevoerd in %d tabel(len).', $total_changes, count( $table_results ) ),
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
