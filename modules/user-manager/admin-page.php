<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------
 *  Menu registratie
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'User Manager',
        'User Manager',
        'manage_options',
        'dp-toolbox-user-manager',
        'dp_toolbox_um_page'
    );
} );

/* ------------------------------------------------------------------
 *  Form-save handler (form-POST + redirect, geen AJAX)
 * ------------------------------------------------------------------ */
add_action( 'admin_post_dp_toolbox_um_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toegang' );
    }
    if ( ! dp_toolbox_um_is_superadmin() ) {
        wp_die( 'Alleen @designpixels.nl accounts mogen User Manager bedienen.' );
    }
    check_admin_referer( 'dp_toolbox_um_save' );

    $target_uid = absint( $_POST['target_user'] ?? 0 );
    if ( ! $target_uid ) {
        wp_die( 'Geen gebruiker gekozen.' );
    }

    // Kan nooit een superadmin blokkeren
    if ( dp_toolbox_um_is_superadmin( $target_uid ) ) {
        wp_die( 'Deze gebruiker staat op de whitelist en kan niet worden beperkt.' );
    }

    $plugins = array_map( 'sanitize_text_field', (array) ( $_POST['hidden_plugins'] ?? [] ) );
    $menus   = array_map( 'sanitize_text_field', (array) ( $_POST['hidden_menus']   ?? [] ) );

    // Submenu's verwachten [parent_slug][] = sub_slug
    $subs_raw = (array) ( $_POST['hidden_submenus'] ?? [] );
    $subs     = [];
    foreach ( $subs_raw as $parent => $slugs ) {
        $parent = sanitize_text_field( $parent );
        $subs[ $parent ] = array_map( 'sanitize_text_field', (array) $slugs );
    }

    update_option( 'dp_toolbox_um_user_' . $target_uid, [
        'plugins'  => array_values( array_unique( $plugins ) ),
        'menus'    => array_values( array_unique( $menus ) ),
        'submenus' => $subs,
    ] );

    $redirect = add_query_arg( [
        'page'    => 'dp-toolbox-user-manager',
        'user'    => $target_uid,
        'updated' => '1',
    ], admin_url( 'admin.php' ) );
    wp_safe_redirect( $redirect );
    exit;
} );

/* ------------------------------------------------------------------
 *  Render pagina
 * ------------------------------------------------------------------ */
function dp_toolbox_um_page() {
    if ( ! dp_toolbox_um_is_superadmin() ) {
        wp_die( 'Alleen @designpixels.nl accounts hebben toegang tot User Manager.' );
    }

    // Alle administrators ophalen
    $admins = get_users( [ 'role' => 'administrator', 'orderby' => 'display_name' ] );

    // Geselecteerde user bepalen (alleen klant-admins, geen superadmins)
    $selected_uid = absint( $_GET['user'] ?? 0 );
    $selected_user = null;
    foreach ( $admins as $u ) {
        if ( $u->ID === $selected_uid && ! dp_toolbox_um_is_superadmin( $u->ID ) ) {
            $selected_user = $u;
            break;
        }
    }
    if ( ! $selected_user ) {
        // Default: eerste niet-superadmin
        foreach ( $admins as $u ) {
            if ( ! dp_toolbox_um_is_superadmin( $u->ID ) ) {
                $selected_user = $u;
                break;
            }
        }
    }

    // Menu-structuur ophalen uit Role Manager's transient
    $all_menus = get_transient( 'dp_toolbox_rm_all_menus' )   ?: [];
    $all_subs  = get_transient( 'dp_toolbox_rm_all_submenus' ) ?: [];
    ksort( $all_menus );

    // Plugins ophalen
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();

    // Huidige settings voor geselecteerde user
    $settings = $selected_user ? dp_toolbox_um_get_settings( $selected_user->ID ) : [ 'plugins' => [], 'menus' => [], 'submenus' => [] ];

    dp_toolbox_page_start( 'User Manager', 'Beheer per administrator welke plugins en sidebar-items zichtbaar zijn.' );

    if ( ! empty( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>';
    }

    if ( empty( $all_menus ) ) {
        echo '<div class="notice notice-warning"><p><strong>Menu-structuur nog niet gecached.</strong> Open eerst een willekeurige andere admin-pagina (bv. Dashboard) om de menu-structuur te laden, kom daarna terug.</p></div>';
    }
    ?>

    <style>
        .dp-um-layout {
            display: grid; grid-template-columns: 280px 1fr; gap: 0;
            background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden;
        }
        .dp-um-users {
            background: #f8f7fc; border-right: 1px solid #e0e0e0;
            max-height: 700px; overflow: auto;
        }
        .dp-um-users-title {
            font-size: 11px; font-weight: 700; color: #281E5D;
            text-transform: uppercase; letter-spacing: 0.8px;
            padding: 14px 18px 8px;
        }
        .dp-um-user {
            display: block; padding: 12px 18px;
            border-bottom: 1px solid #efecf6; text-decoration: none; color: inherit;
            transition: background 0.15s;
            position: relative;
        }
        .dp-um-user:hover { background: #f3f0ff; color: inherit; }
        .dp-um-user.is-active {
            background: #fff; border-left: 3px solid #281E5D; padding-left: 15px;
        }
        .dp-um-user.is-disabled {
            opacity: 0.6; cursor: not-allowed; pointer-events: none;
            background: #f0ecf8;
        }
        .dp-um-user-name { font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-um-user-email { font-size: 11px; color: #888; margin-top: 2px; word-break: break-all; }
        .dp-um-user-badge {
            display: inline-block; font-size: 9px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            background: #281E5D; color: #fff; padding: 2px 6px; border-radius: 3px;
            margin-top: 4px;
        }
        .dp-um-user-badge.you { background: #c48a00; }

        .dp-um-main { padding: 24px 28px; max-height: 700px; overflow: auto; }
        .dp-um-empty { color: #888; font-size: 13px; text-align: center; padding: 40px 0; }

        .dp-um-header {
            padding-bottom: 14px; margin-bottom: 18px;
            border-bottom: 2px solid #281E5D;
        }
        .dp-um-header h2 { margin: 0; font-size: 16px; font-weight: 700; color: #1d2327; }
        .dp-um-header p { margin: 2px 0 0; color: #888; font-size: 12px; }

        .dp-um-section { margin-bottom: 24px; }
        .dp-um-section-title {
            display: flex; align-items: center; gap: 8px;
            font-size: 11px; font-weight: 700; color: #281E5D;
            text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 10px; padding-bottom: 6px;
            border-bottom: 1px solid #e8e5f0;
        }
        .dp-um-section-title .dashicons { font-size: 16px; width: 16px; height: 16px; }

        .dp-um-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; border-radius: 6px;
            transition: background 0.15s;
        }
        .dp-um-item:hover { background: #f8f7fc; }
        .dp-um-item input[type="checkbox"] { margin: 0; flex-shrink: 0; }
        .dp-um-item-label { flex: 1; font-size: 13px; color: #1d2327; }
        .dp-um-item-label code { background: #f0ecff; color: #281E5D; font-size: 11px; padding: 1px 6px; border-radius: 3px; margin-left: 4px; }
        .dp-um-item-meta { font-size: 11px; color: #aaa; }

        .dp-um-actions {
            display: flex; gap: 10px; align-items: center;
            margin-top: 20px; padding-top: 16px; border-top: 1px solid #e8e5f0;
        }
        .dp-um-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 9px 22px; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background 0.2s;
        }
        .dp-um-btn:hover { background: #4a3a8a; }
        .dp-um-warning {
            background: #fef9ee; border: 1px solid #f0e0b8; border-radius: 6px;
            padding: 10px 14px; margin-bottom: 16px;
            font-size: 12px; color: #78350f; line-height: 1.5;
        }
        .dp-um-warning .dashicons { color: #c48a00; font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; }

        @media (max-width: 900px) {
            .dp-um-layout { grid-template-columns: 1fr; }
            .dp-um-users { border-right: none; border-bottom: 1px solid #e0e0e0; max-height: 300px; }
        }
    </style>

    <div class="dp-um-layout">

        <!-- USERS KOLOM -->
        <div class="dp-um-users">
            <div class="dp-um-users-title">Administrators</div>
            <?php
            $current_uid = get_current_user_id();
            foreach ( $admins as $u ) :
                $is_super    = dp_toolbox_um_is_superadmin( $u->ID );
                $is_self     = ( $u->ID === $current_uid );
                $is_disabled = $is_super || $is_self;
                $is_active   = ( $selected_user && $u->ID === $selected_user->ID );

                $url = add_query_arg( [
                    'page' => 'dp-toolbox-user-manager',
                    'user' => $u->ID,
                ], admin_url( 'admin.php' ) );

                $classes = 'dp-um-user';
                if ( $is_disabled ) $classes .= ' is-disabled';
                if ( $is_active )   $classes .= ' is-active';
            ?>
                <<?php echo $is_disabled ? 'div' : 'a'; ?>
                    class="<?php echo esc_attr( $classes ); ?>"
                    <?php if ( ! $is_disabled ) : ?>href="<?php echo esc_url( $url ); ?>"<?php endif; ?>>
                    <div class="dp-um-user-name"><?php echo esc_html( $u->display_name ); ?></div>
                    <div class="dp-um-user-email"><?php echo esc_html( $u->user_email ); ?></div>
                    <?php if ( $is_super ) : ?>
                        <span class="dp-um-user-badge">&#128737; Whitelisted</span>
                    <?php elseif ( $is_self ) : ?>
                        <span class="dp-um-user-badge you">Jij</span>
                    <?php endif; ?>
                </<?php echo $is_disabled ? 'div' : 'a'; ?>>
            <?php endforeach; ?>
        </div>

        <!-- INSTELLINGEN KOLOM -->
        <div class="dp-um-main">
            <?php if ( ! $selected_user ) : ?>
                <div class="dp-um-empty">
                    Geen beheerbare administrators gevonden.<br>
                    Alle admin-accounts op deze site zijn whitelisted (@designpixels.nl) of zijn jouw eigen account.
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="dp_toolbox_um_save">
                    <input type="hidden" name="target_user" value="<?php echo esc_attr( $selected_user->ID ); ?>">
                    <?php wp_nonce_field( 'dp_toolbox_um_save' ); ?>

                    <div class="dp-um-header">
                        <h2><?php echo esc_html( $selected_user->display_name ); ?></h2>
                        <p><?php echo esc_html( $selected_user->user_email ); ?></p>
                    </div>

                    <div class="dp-um-warning">
                        <span class="dashicons dashicons-info"></span>
                        Aangevinkte items worden <strong>verborgen</strong> voor deze gebruiker. De UI wordt alleen verborgen — directe URL-toegang blijft werken.
                    </div>

                    <!-- Plugins -->
                    <div class="dp-um-section">
                        <div class="dp-um-section-title">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            Plugins verbergen uit pluginlijst
                        </div>
                        <?php if ( empty( $all_plugins ) ) : ?>
                            <p class="dp-um-empty">Geen plugins gevonden.</p>
                        <?php else : ?>
                            <?php foreach ( $all_plugins as $path => $plugin ) :
                                $checked = in_array( $path, $settings['plugins'], true );
                            ?>
                                <label class="dp-um-item">
                                    <input type="checkbox" name="hidden_plugins[]"
                                           value="<?php echo esc_attr( $path ); ?>"
                                           <?php checked( $checked ); ?>>
                                    <span class="dp-um-item-label">
                                        <?php echo esc_html( $plugin['Name'] ); ?>
                                        <code><?php echo esc_html( $path ); ?></code>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar menu -->
                    <div class="dp-um-section">
                        <div class="dp-um-section-title">
                            <span class="dashicons dashicons-menu"></span>
                            Sidebar menu-items verbergen
                        </div>
                        <?php if ( empty( $all_menus ) ) : ?>
                            <p class="dp-um-empty">Menu-structuur nog niet geladen.</p>
                        <?php else : ?>
                            <?php foreach ( $all_menus as $menu_item ) :
                                if ( empty( $menu_item[0] ) || empty( $menu_item[2] ) ) continue;
                                if ( strpos( $menu_item[0], 'wp-menu-separator' ) !== false ) continue;

                                $slug    = $menu_item[2];
                                $label   = wp_strip_all_tags( $menu_item[0] );
                                $checked = in_array( $slug, $settings['menus'], true );
                            ?>
                                <label class="dp-um-item">
                                    <input type="checkbox" name="hidden_menus[]"
                                           value="<?php echo esc_attr( $slug ); ?>"
                                           <?php checked( $checked ); ?>>
                                    <span class="dp-um-item-label">
                                        <?php echo esc_html( $label ); ?>
                                        <code><?php echo esc_html( $slug ); ?></code>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="dp-um-actions">
                        <button type="submit" class="dp-um-btn">Opslaan</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

    </div>

    <?php
    dp_toolbox_page_end();
}
