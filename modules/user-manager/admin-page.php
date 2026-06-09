<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------
 *  Menu registratie
 *  De User Manager (incl. rollen) als eigen submenu-pagina onder DP Toolbox.
 *  Slug 'dp-toolbox-user-manager' moet bestaan: de interne links + de
 *  save-redirect wijzen ernaar. Alleen voor superadmins (@designpixels.nl).
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    if ( ! dp_toolbox_um_is_superadmin() ) {
        return;
    }
    add_submenu_page(
        'dp-toolbox',
        'User Manager',
        'User Manager',
        'manage_options',
        'dp-toolbox-user-manager',
        function () {
            echo '<div class="wrap"><h1 style="margin:0 0 16px;">User Manager</h1>';
            dp_toolbox_um_render_inline();
            echo '</div>';
        }
    );
}, 20 );

/* ------------------------------------------------------------------
 *  Niet-admin rollen ophalen (alles behalve administrator)
 * ------------------------------------------------------------------ */
function dp_toolbox_um_get_roles() {
    $roles = wp_roles()->role_names;
    unset( $roles['administrator'] );
    return $roles;
}

/* ------------------------------------------------------------------
 *  Settings van een ROL ophalen (per-rol menu's/subs, globale plugins)
 * ------------------------------------------------------------------ */
function dp_toolbox_um_get_role_settings( $role ) {
    return [
        'plugins'  => (array) get_option( 'dp_toolbox_rm_hidden_plugins', [] ), // globaal
        'menus'    => (array) get_option( 'dp_toolbox_rm_hidden_menus_' . $role, [] ),
        'submenus' => (array) get_option( 'dp_toolbox_rm_hidden_submenus_' . $role, [] ),
    ];
}

/* ------------------------------------------------------------------
 *  Form-save handler (form-POST + redirect, geen AJAX)
 *  Werkt voor zowel een admin-user (per-user) als een rol (per-rol).
 * ------------------------------------------------------------------ */
add_action( 'admin_post_dp_toolbox_um_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toegang' );
    }
    if ( ! dp_toolbox_um_is_superadmin() ) {
        wp_die( 'Alleen @designpixels.nl accounts mogen User Manager bedienen.' );
    }
    check_admin_referer( 'dp_toolbox_um_save' );

    // Gemeenschappelijke velden
    $plugins = array_map( 'sanitize_text_field', (array) ( $_POST['hidden_plugins'] ?? [] ) );
    $menus   = array_map( 'sanitize_text_field', (array) ( $_POST['hidden_menus']   ?? [] ) );

    $subs_raw = (array) ( $_POST['hidden_submenus'] ?? [] );
    $subs     = [];
    foreach ( $subs_raw as $parent => $slugs ) {
        $parent           = sanitize_text_field( $parent );
        $subs[ $parent ]  = array_map( 'sanitize_text_field', (array) $slugs );
    }

    $plugins = array_values( array_unique( $plugins ) );
    $menus   = array_values( array_unique( $menus ) );

    $target_role = isset( $_POST['target_role'] ) ? sanitize_key( wp_unslash( $_POST['target_role'] ) ) : '';
    $target_uid  = absint( $_POST['target_user'] ?? 0 );

    /* ---- ROL-modus ---- */
    if ( $target_role !== '' ) {
        $roles = dp_toolbox_um_get_roles();
        if ( ! isset( $roles[ $target_role ] ) ) {
            wp_die( 'Onbekende rol.' );
        }
        update_option( 'dp_toolbox_rm_hidden_menus_' . $target_role, $menus );
        update_option( 'dp_toolbox_rm_hidden_submenus_' . $target_role, $subs );
        update_option( 'dp_toolbox_rm_hidden_plugins', $plugins ); // globaal voor alle rollen

        $redirect = add_query_arg( [
            'page'    => 'dp-toolbox-user-manager',
            'role'    => $target_role,
            'updated' => '1',
        ], admin_url( 'admin.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ---- USER-modus (admin) ---- */
    if ( ! $target_uid ) {
        wp_die( 'Geen gebruiker of rol gekozen.' );
    }
    if ( dp_toolbox_um_is_superadmin( $target_uid ) ) {
        wp_die( 'Deze gebruiker staat op de whitelist en kan niet worden beperkt.' );
    }

    update_option( 'dp_toolbox_um_user_' . $target_uid, [
        'plugins'  => $plugins,
        'menus'    => $menus,
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
function dp_toolbox_um_render_inline() {
    if ( ! dp_toolbox_um_is_superadmin() ) {
        wp_die( 'Alleen @designpixels.nl accounts hebben toegang tot User Manager.' );
    }

    $admins = get_users( [ 'role' => 'administrator', 'orderby' => 'display_name' ] );
    $roles  = dp_toolbox_um_get_roles();

    $role_counts = count_users();
    $role_counts = (array) ( $role_counts['avail_roles'] ?? [] );

    // ---- Selectie bepalen ----
    $sel_role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '';
    $sel_uid  = absint( $_GET['user'] ?? 0 );

    $mode          = '';   // 'user' | 'role'
    $selected_user = null;
    $selected_role = '';

    if ( $sel_role !== '' && isset( $roles[ $sel_role ] ) ) {
        $mode          = 'role';
        $selected_role = $sel_role;
    } else {
        // user-modus (alleen klant-admins, geen superadmins)
        foreach ( $admins as $u ) {
            if ( (int) $u->ID === $sel_uid && ! dp_toolbox_um_is_superadmin( $u->ID ) ) {
                $selected_user = $u;
                break;
            }
        }
        if ( ! $selected_user && $sel_uid === 0 ) {
            // Default: eerste niet-superadmin admin, anders eerste rol
            foreach ( $admins as $u ) {
                if ( ! dp_toolbox_um_is_superadmin( $u->ID ) ) {
                    $selected_user = $u;
                    break;
                }
            }
            if ( ! $selected_user && ! empty( $roles ) ) {
                $mode          = 'role';
                $selected_role = array_key_first( $roles );
            }
        }
        if ( $selected_user ) {
            $mode = 'user';
        }
    }

    // ---- Data voor het rechterpaneel ----
    $all_menus = get_transient( 'dp_toolbox_rm_all_menus' )   ?: [];
    $all_subs  = get_transient( 'dp_toolbox_rm_all_submenus' ) ?: [];
    ksort( $all_menus );

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();

    if ( $mode === 'role' ) {
        $settings = dp_toolbox_um_get_role_settings( $selected_role );
        $title    = $roles[ $selected_role ];
        $subtitle = 'Rol — geldt voor iedereen met deze rol';
    } elseif ( $mode === 'user' ) {
        $settings = dp_toolbox_um_get_settings( $selected_user->ID );
        $title    = $selected_user->display_name;
        $subtitle = $selected_user->user_email;
    } else {
        $settings = [ 'plugins' => [], 'menus' => [], 'submenus' => [] ];
        $title = ''; $subtitle = '';
    }

    if ( ! empty( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>';
    }
    if ( empty( $all_menus ) ) {
        echo '<div class="notice notice-warning"><p><strong>Menu-structuur nog niet gecached.</strong> Open eerst een willekeurige andere admin-pagina (bv. Dashboard) om de menu-structuur te laden, kom daarna terug.</p></div>';
    }
    ?>

    <style>
        .dp-um-layout { display: grid; grid-template-columns: 280px 1fr; gap: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; }
        .dp-um-users { background: #f8f7fc; border-right: 1px solid #e0e0e0; max-height: 760px; overflow: auto; }
        .dp-um-users-title { font-size: 11px; font-weight: 700; color: #281E5D; text-transform: uppercase; letter-spacing: 0.8px; padding: 14px 18px 8px; }
        .dp-um-users-title.dp-um-group2 { margin-top: 6px; border-top: 1px solid #e6e1f2; padding-top: 14px; }
        .dp-um-user { display: block; padding: 12px 18px; border-bottom: 1px solid #efecf6; text-decoration: none; color: inherit; transition: background 0.15s; position: relative; }
        .dp-um-user:hover { background: #f3f0ff; color: inherit; }
        .dp-um-user.is-active { background: #fff; border-left: 3px solid #281E5D; padding-left: 15px; }
        .dp-um-user.is-disabled { opacity: 0.6; cursor: not-allowed; pointer-events: none; background: #f0ecf8; }
        .dp-um-user-name { font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-um-user-email { font-size: 11px; color: #888; margin-top: 2px; word-break: break-all; }
        .dp-um-user-badge { display: inline-block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #281E5D; color: #fff; padding: 2px 6px; border-radius: 3px; margin-top: 4px; }
        .dp-um-user-badge.you { background: #c48a00; }
        .dp-um-user-badge.role { background: #2a7d5f; }

        .dp-um-main { padding: 24px 28px; max-height: 760px; overflow: auto; }
        .dp-um-empty { color: #888; font-size: 13px; text-align: center; padding: 40px 0; }

        .dp-um-header { padding-bottom: 14px; margin-bottom: 18px; border-bottom: 2px solid #281E5D; }
        .dp-um-header h2 { margin: 0; font-size: 16px; font-weight: 700; color: #1d2327; }
        .dp-um-header p { margin: 2px 0 0; color: #888; font-size: 12px; }

        .dp-um-section { margin-bottom: 24px; }
        .dp-um-section-title { display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 700; color: #281E5D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e8e5f0; }
        .dp-um-section-title .dashicons { font-size: 16px; width: 16px; height: 16px; }
        .dp-um-subgroup { font-size: 11px; font-weight: 700; color: #555; margin: 12px 0 4px; padding-left: 2px; }

        .dp-um-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; transition: background 0.15s; }
        .dp-um-item.sub { padding-left: 26px; }
        .dp-um-item:hover { background: #f8f7fc; }
        .dp-um-item input[type="checkbox"] { margin: 0; flex-shrink: 0; }
        .dp-um-item-label { flex: 1; font-size: 13px; color: #1d2327; }
        .dp-um-item-label code { background: #f0ecff; color: #281E5D; font-size: 11px; padding: 1px 6px; border-radius: 3px; margin-left: 4px; }

        .dp-um-actions { display: flex; gap: 10px; align-items: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e8e5f0; }
        .dp-um-btn { background: #281E5D; color: #fff; border: none; border-radius: 6px; padding: 9px 22px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .dp-um-btn:hover { background: #4a3a8a; }
        .dp-um-warning { background: #fef9ee; border: 1px solid #f0e0b8; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; color: #78350f; line-height: 1.5; }
        .dp-um-warning .dashicons { color: #c48a00; font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; }
        .dp-um-note { font-size: 11px; color: #888; margin: 0 0 8px; font-style: italic; }

        @media (max-width: 900px) {
            .dp-um-layout { grid-template-columns: 1fr; }
            .dp-um-users { border-right: none; border-bottom: 1px solid #e0e0e0; max-height: 300px; }
        }
    </style>

    <div class="dp-um-layout">

        <!-- LINKERKOLOM: admins + rollen -->
        <div class="dp-um-users">

            <div class="dp-um-users-title">Administrators</div>
            <?php
            $current_uid = get_current_user_id();
            foreach ( $admins as $u ) :
                $is_super    = dp_toolbox_um_is_superadmin( $u->ID );
                $is_self     = ( (int) $u->ID === (int) $current_uid );
                $is_disabled = $is_super || $is_self;
                $is_active   = ( $mode === 'user' && $selected_user && (int) $u->ID === (int) $selected_user->ID );

                $url = add_query_arg( [
                    'page' => 'dp-toolbox-user-manager',
                    'user' => $u->ID,
                ], admin_url( 'admin.php' ) );

                $classes = 'dp-um-user';
                if ( $is_disabled ) $classes .= ' is-disabled';
                if ( $is_active )   $classes .= ' is-active';
            ?>
                <<?php echo $is_disabled ? 'div' : 'a'; ?> class="<?php echo esc_attr( $classes ); ?>" <?php if ( ! $is_disabled ) : ?>href="<?php echo esc_url( $url ); ?>"<?php endif; ?>>
                    <div class="dp-um-user-name"><?php echo esc_html( $u->display_name ); ?></div>
                    <div class="dp-um-user-email"><?php echo esc_html( $u->user_email ); ?></div>
                    <?php if ( $is_super ) : ?>
                        <span class="dp-um-user-badge">&#128737; Whitelisted</span>
                    <?php elseif ( $is_self ) : ?>
                        <span class="dp-um-user-badge you">Jij</span>
                    <?php endif; ?>
                </<?php echo $is_disabled ? 'div' : 'a'; ?>>
            <?php endforeach; ?>

            <div class="dp-um-users-title dp-um-group2">Rollen</div>
            <?php foreach ( $roles as $slug => $name ) :
                $is_active = ( $mode === 'role' && $selected_role === $slug );
                $count     = (int) ( $role_counts[ $slug ] ?? 0 );
                $url = add_query_arg( [
                    'page' => 'dp-toolbox-user-manager',
                    'role' => $slug,
                ], admin_url( 'admin.php' ) );
            ?>
                <a class="dp-um-user<?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
                    <div class="dp-um-user-name"><?php echo esc_html( $name ); ?></div>
                    <div class="dp-um-user-email"><?php echo $count; ?> gebruiker<?php echo $count === 1 ? '' : 's'; ?> &middot; <code style="font-size:10px;"><?php echo esc_html( $slug ); ?></code></div>
                    <span class="dp-um-user-badge role">Rol</span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- RECHTERPANEEL -->
        <div class="dp-um-main">
            <?php if ( ! $selected_user && $mode !== 'role' ) : ?>
                <div class="dp-um-empty">Kies links een administrator of rol om te beheren.</div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="dp_toolbox_um_save">
                    <?php if ( $mode === 'role' ) : ?>
                        <input type="hidden" name="target_role" value="<?php echo esc_attr( $selected_role ); ?>">
                    <?php else : ?>
                        <input type="hidden" name="target_user" value="<?php echo esc_attr( $selected_user->ID ); ?>">
                    <?php endif; ?>
                    <?php wp_nonce_field( 'dp_toolbox_um_save' ); ?>

                    <div class="dp-um-header">
                        <h2><?php echo esc_html( $title ); ?></h2>
                        <p><?php echo esc_html( $subtitle ); ?></p>
                    </div>

                    <div class="dp-um-warning">
                        <span class="dashicons dashicons-info"></span>
                        Aangevinkte items worden <strong>verborgen</strong>
                        <?php echo $mode === 'role' ? 'voor iedereen met deze rol' : 'voor deze gebruiker'; ?>.
                        De UI wordt alleen verborgen — directe URL-toegang blijft werken.
                    </div>

                    <!-- Plugins -->
                    <div class="dp-um-section">
                        <div class="dp-um-section-title">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            Plugins verbergen uit pluginlijst
                        </div>
                        <?php if ( $mode === 'role' ) : ?>
                            <p class="dp-um-note">Let op: de plugin-selectie geldt voor <strong>alle rollen</strong> (één gedeelde lijst voor niet-administrators).</p>
                        <?php endif; ?>
                        <?php if ( empty( $all_plugins ) ) : ?>
                            <p class="dp-um-empty">Geen plugins gevonden.</p>
                        <?php else : foreach ( $all_plugins as $path => $plugin ) :
                            $checked = in_array( $path, (array) $settings['plugins'], true );
                        ?>
                            <label class="dp-um-item">
                                <input type="checkbox" name="hidden_plugins[]" value="<?php echo esc_attr( $path ); ?>" <?php checked( $checked ); ?>>
                                <span class="dp-um-item-label"><?php echo esc_html( $plugin['Name'] ); ?> <code><?php echo esc_html( $path ); ?></code></span>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>

                    <!-- Menu-items -->
                    <div class="dp-um-section">
                        <div class="dp-um-section-title">
                            <span class="dashicons dashicons-menu"></span>
                            Sidebar menu-items verbergen
                        </div>
                        <?php if ( empty( $all_menus ) ) : ?>
                            <p class="dp-um-empty">Menu-structuur nog niet geladen.</p>
                        <?php else : foreach ( $all_menus as $menu_item ) :
                            if ( empty( $menu_item[0] ) || empty( $menu_item[2] ) ) continue;
                            if ( strpos( $menu_item[0], 'wp-menu-separator' ) !== false ) continue;
                            $slug    = $menu_item[2];
                            $label   = wp_strip_all_tags( $menu_item[0] );
                            $checked = in_array( $slug, (array) $settings['menus'], true );
                        ?>
                            <label class="dp-um-item">
                                <input type="checkbox" name="hidden_menus[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>>
                                <span class="dp-um-item-label"><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code></span>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>

                    <!-- Submenu-items -->
                    <?php if ( ! empty( $all_subs ) ) : ?>
                    <div class="dp-um-section">
                        <div class="dp-um-section-title">
                            <span class="dashicons dashicons-arrow-right"></span>
                            Submenu-items verbergen
                        </div>
                        <?php foreach ( $all_menus as $menu_item ) :
                            if ( empty( $menu_item[2] ) ) continue;
                            $parent = $menu_item[2];
                            if ( empty( $all_subs[ $parent ] ) ) continue;
                            $parent_label = wp_strip_all_tags( $menu_item[0] );
                            $hidden_subs  = (array) ( $settings['submenus'][ $parent ] ?? [] );
                        ?>
                            <div class="dp-um-subgroup"><?php echo esc_html( $parent_label ); ?></div>
                            <?php foreach ( $all_subs[ $parent ] as $sub ) :
                                if ( empty( $sub[0] ) || empty( $sub[2] ) ) continue;
                                $sub_slug  = $sub[2];
                                $sub_label = wp_strip_all_tags( $sub[0] );
                                $checked   = in_array( $sub_slug, $hidden_subs, true );
                            ?>
                                <label class="dp-um-item sub">
                                    <input type="checkbox" name="hidden_submenus[<?php echo esc_attr( $parent ); ?>][]" value="<?php echo esc_attr( $sub_slug ); ?>" <?php checked( $checked ); ?>>
                                    <span class="dp-um-item-label"><?php echo esc_html( $sub_label ); ?> <code><?php echo esc_html( $sub_slug ); ?></code></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="dp-um-actions">
                        <button type="submit" class="dp-um-btn">Opslaan</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

    </div>
    <?php
}
