<?php
/**
 * Name: Fix WP undefined plugin warning (EDD-SL en andere oude updaters)
 * Description: Patcht plugin-update entries in de update_plugins transient die de 'plugin' property missen. Voorkomt "Undefined property: stdClass::$plugin in wp-includes/class-wp-list-util.php" die ontstaat bij oudere EDD_SL_Plugin_Updater builds (o.a. Max Addons Pro for Bricks). De fix is veilig en transparant: de array-key in response/no_update IS al de plugin-pad (slug/file.php), dus we kopiëren die naar de property.
 * Sites: *
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'site_transient_update_plugins', function ( $value ) {
    if ( ! is_object( $value ) ) {
        return $value;
    }
    foreach ( [ 'response', 'no_update' ] as $bucket ) {
        if ( ! isset( $value->$bucket ) || ! is_array( $value->$bucket ) ) {
            continue;
        }
        foreach ( $value->$bucket as $key => $entry ) {
            if ( is_object( $entry ) && ! isset( $entry->plugin ) ) {
                $entry->plugin           = $key;
                $value->$bucket[ $key ]  = $entry;
            }
        }
    }
    return $value;
}, 999 );
