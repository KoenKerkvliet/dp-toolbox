<?php
/**
 * Module Name: User Manager
 * Description: Verberg menu-items en plugins per individuele administrator.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 *  Superadmin whitelist (hardcoded)
 *  Users met @designpixels.nl e-mailadres zijn altijd immuun voor
 *  hide-rules en kunnen nooit via de UI geblokkeerd worden.
 * ------------------------------------------------------------------ */
function dp_toolbox_um_is_superadmin( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) return false;

    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->user_email ) ) return false;

    $email = strtolower( trim( $user->user_email ) );
    return str_ends_with( $email, '@designpixels.nl' );
}

/* ------------------------------------------------------------------
 *  Settings-helper
 * ------------------------------------------------------------------ */
function dp_toolbox_um_get_settings( $user_id ) {
    $settings = get_option( 'dp_toolbox_um_user_' . (int) $user_id, [] );
    if ( ! is_array( $settings ) ) return [];
    return [
        'plugins'  => (array) ( $settings['plugins']  ?? [] ),
        'menus'    => (array) ( $settings['menus']    ?? [] ),
        'submenus' => (array) ( $settings['submenus'] ?? [] ),
    ];
}

/* ------------------------------------------------------------------
 *  1. Verberg plugins uit de pluginlijst per user
 *     Priority 110 — na Role Manager's 101
 * ------------------------------------------------------------------ */
add_filter( 'all_plugins', function ( $plugins ) {
    $uid = get_current_user_id();
    if ( ! $uid || dp_toolbox_um_is_superadmin( $uid ) ) {
        return $plugins;
    }

    $settings = dp_toolbox_um_get_settings( $uid );
    foreach ( $settings['plugins'] as $path ) {
        unset( $plugins[ $path ] );
    }
    return $plugins;
}, 110 );

/* ------------------------------------------------------------------
 *  2. Verberg sidebar menu- en submenu-items per user
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    $uid = get_current_user_id();
    if ( ! $uid || dp_toolbox_um_is_superadmin( $uid ) ) {
        return;
    }

    $settings = dp_toolbox_um_get_settings( $uid );

    foreach ( $settings['menus'] as $slug ) {
        remove_menu_page( $slug );
    }
    foreach ( $settings['submenus'] as $parent => $subs ) {
        foreach ( (array) $subs as $sub ) {
            remove_submenu_page( $parent, $sub );
        }
    }
}, 999999 );

/* ------------------------------------------------------------------
 *  3. User Manager zelf verbergen voor niet-superadmins
 *     Extra veiligheid bovenop de dp_toolbox_current_user_has_access
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    if ( ! dp_toolbox_um_is_superadmin() ) {
        remove_submenu_page( 'dp-toolbox', 'dp-toolbox-user-manager' );
    }
}, PHP_INT_MAX );

/* ------------------------------------------------------------------
 *  4. Opruimen bij gebruiker-verwijdering
 * ------------------------------------------------------------------ */
add_action( 'delete_user', function ( $user_id ) {
    delete_option( 'dp_toolbox_um_user_' . (int) $user_id );
} );

/* ------------------------------------------------------------------
 *  Admin pagina
 * ------------------------------------------------------------------ */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
