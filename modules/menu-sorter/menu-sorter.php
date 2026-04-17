<?php
/**
 * Module Name: Menu Sorter
 * Description: Pas de volgorde van items in de admin-sidebar aan via drag & drop.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Capture the full admin menu for the settings UI.
 * Runs late so all plugins have registered their items.
 */
add_action( 'admin_menu', function () {
    global $menu;
    if ( ! is_array( $menu ) || ! current_user_can( 'manage_options' ) ) return;

    $items = [];
    foreach ( $menu as $pos => $item ) {
        $title = $item[0] ?? '';
        $slug  = $item[2] ?? '';
        $icon  = $item[6] ?? '';
        $class = $item[4] ?? '';

        // Detect separator
        $is_sep = ( strpos( $class, 'wp-menu-separator' ) !== false ) || $slug === 'separator1' || $slug === 'separator2' || $slug === 'separator-last' || ( empty( $title ) && strpos( $slug, 'separator' ) !== false );

        // Strip HTML from title (notification bubbles etc.)
        $clean_title = wp_strip_all_tags( $title );
        // Remove count numbers like " 3" at the end
        $clean_title = preg_replace( '/\s*\d+$/', '', $clean_title );

        $items[] = [
            'position'  => $pos,
            'title'     => $clean_title ?: ( $is_sep ? '--- Scheidingslijn ---' : $slug ),
            'slug'      => $slug,
            'icon'      => $icon,
            'class'     => $class,
            'separator' => $is_sep,
        ];
    }

    set_transient( 'dp_toolbox_admin_menu_items', $items, DAY_IN_SECONDS );
}, PHP_INT_MAX - 10 );

/**
 * Apply saved menu order.
 */
add_action( 'admin_menu', function () {
    $order = get_option( 'dp_toolbox_menu_order', [] );
    if ( empty( $order ) || ! is_array( $order ) ) return;

    global $menu;
    if ( ! is_array( $menu ) ) return;

    // Build lookup: slug => menu item
    $lookup = [];
    foreach ( $menu as $item ) {
        $slug = $item[2] ?? '';
        $lookup[ $slug ] = $item;
    }

    // Rebuild menu in saved order
    $new_menu = [];
    $position = 1;

    // First: items in saved order
    foreach ( $order as $slug ) {
        if ( isset( $lookup[ $slug ] ) ) {
            $new_menu[ $position ] = $lookup[ $slug ];
            unset( $lookup[ $slug ] );
            $position++;
        }
    }

    // Then: any new items not yet in saved order (new plugins)
    foreach ( $lookup as $slug => $item ) {
        $new_menu[ $position ] = $item;
        $position++;
    }

    $menu = $new_menu;
}, PHP_INT_MAX );

/**
 * AJAX: save menu order.
 */
add_action( 'wp_ajax_dp_toolbox_save_menu_order', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'dp_toolbox_menu_sorter', 'nonce' );

    $order = $_POST['order'] ?? [];
    if ( ! is_array( $order ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    $order = array_map( 'sanitize_text_field', $order );
    update_option( 'dp_toolbox_menu_order', $order );
    wp_send_json_success();
} );

/**
 * AJAX: reset menu order.
 */
add_action( 'wp_ajax_dp_toolbox_reset_menu_order', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'dp_toolbox_menu_sorter', 'nonce' );
    delete_option( 'dp_toolbox_menu_order' );
    delete_transient( 'dp_toolbox_admin_menu_items' );
    wp_send_json_success();
} );

/* Admin page (tab) */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}