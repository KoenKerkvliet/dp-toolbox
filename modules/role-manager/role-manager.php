<?php
/**
 * Module Name: Role Manager
 * Description: Verberg menu-items en plugins per gebruikersrol.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 *  1. Menu-structuur vastleggen (voor admin-pagina)
 * ------------------------------------------------------------------ */
add_action( 'admin_head', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $menu, $submenu;

    // Sla de volledige menustructuur op als transient zodat de admin-pagina deze kan tonen
    set_transient( 'dp_toolbox_rm_all_menus', $menu, DAY_IN_SECONDS );
    set_transient( 'dp_toolbox_rm_all_submenus', $submenu, DAY_IN_SECONDS );
}, -1 ); // Priority -1: vóór alles, zodat we het volledige menu zien

/* ------------------------------------------------------------------
 *  2. Menu-items verbergen op basis van rol
 * ------------------------------------------------------------------ */
add_action( 'admin_head', function () {
    $user = wp_get_current_user();

    // Administrators zien altijd alles
    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        return;
    }

    global $menu, $submenu;

    foreach ( (array) $user->roles as $role ) {
        // Hoofdmenu-items verbergen
        $hidden_menus = get_option( 'dp_toolbox_rm_hidden_menus_' . $role, [] );
        if ( ! empty( $hidden_menus ) && is_array( $hidden_menus ) ) {
            foreach ( $hidden_menus as $menu_slug ) {
                remove_menu_page( $menu_slug );
            }
        }

        // Submenu-items verbergen
        $hidden_subs = get_option( 'dp_toolbox_rm_hidden_submenus_' . $role, [] );
        if ( ! empty( $hidden_subs ) && is_array( $hidden_subs ) ) {
            foreach ( $hidden_subs as $parent_slug => $sub_slugs ) {
                foreach ( (array) $sub_slugs as $sub_slug ) {
                    remove_submenu_page( $parent_slug, $sub_slug );
                }
            }
        }
    }
}, 999999 );

/* ------------------------------------------------------------------
 *  3. Plugins verbergen voor niet-administrators
 * ------------------------------------------------------------------ */
add_filter( 'all_plugins', function ( $plugins ) {
    if ( current_user_can( 'manage_options' ) ) {
        return $plugins;
    }

    $hidden = get_option( 'dp_toolbox_rm_hidden_plugins', [] );
    if ( empty( $hidden ) || ! is_array( $hidden ) ) {
        return $plugins;
    }

    foreach ( $hidden as $plugin_path ) {
        unset( $plugins[ $plugin_path ] );
    }

    return $plugins;
}, 101 );

/* ------------------------------------------------------------------
 *  Admin pagina
 * ------------------------------------------------------------------ */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
