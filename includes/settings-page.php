<?php
/**
 * DP Toolbox — Settings Page with Modules + Admin Settings tabs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Access control helpers                                             */
/* ------------------------------------------------------------------ */

function dp_toolbox_current_user_has_access() {
    $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) return false;

    // Allowed roles (default: only administrator)
    $allowed_roles = (array) get_option( 'dp_toolbox_allowed_roles', [ 'administrator' ] );
    $user_roles    = (array) $user->roles;

    $role_match = ! empty( array_intersect( $user_roles, $allowed_roles ) );
    if ( ! $role_match ) return false;

    // Blocked admins
    $blocked_users = (array) get_option( 'dp_toolbox_blocked_users', [] );
    if ( in_array( (string) $user->ID, $blocked_users, true ) ) return false;

    return true;
}

/* ------------------------------------------------------------------ */
/*  Register settings                                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_settings', 'dp_toolbox_enabled_modules', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'sanitize_key', $input ) : [];
        },
        'default' => [],
    ] );
    register_setting( 'dp_toolbox_admin_settings', 'dp_toolbox_allowed_roles', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'sanitize_key', $input ) : [ 'administrator' ];
        },
        'default' => [ 'administrator' ],
    ] );
    register_setting( 'dp_toolbox_admin_settings', 'dp_toolbox_blocked_users', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'absint', $input ) : [];
        },
        'default' => [],
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Menu registration with access control                              */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    // Always register with manage_options, we handle visibility ourselves
    add_menu_page(
        'DP Toolbox',
        'DP Toolbox',
        'manage_options',
        'dp-toolbox',
        'dp_toolbox_settings_page',
        'dashicons-admin-tools',
        3 // Direct under Dashboard (2)
    );
    add_submenu_page(
        'dp-toolbox',
        'Modules',
        'Modules',
        'manage_options',
        'dp-toolbox'
    );
}, 9 );

// Sort submenu items alphabetically (after all modules registered their pages)
add_action( 'admin_menu', function () {
    global $submenu;
    if ( empty( $submenu['dp-toolbox'] ) ) return;

    // Eerste item ("Modules") behouden bovenaan
    $first = array_shift( $submenu['dp-toolbox'] );
    usort( $submenu['dp-toolbox'], function ( $a, $b ) {
        return strcasecmp( $a[0], $b[0] );
    } );
    array_unshift( $submenu['dp-toolbox'], $first );
}, 999 );

// Hide menu for users without access
add_action( 'admin_menu', function () {
    if ( ! dp_toolbox_current_user_has_access() ) {
        remove_menu_page( 'dp-toolbox' );
    }
}, PHP_INT_MAX );

/* Settings link on Plugins page */
add_filter( 'plugin_action_links_dp-toolbox/dp-toolbox.php', function ( $links ) {
    $url  = admin_url( 'admin.php?page=dp-toolbox' );
    $link = '<a href="' . esc_url( $url ) . '">Instellingen</a>';
    array_unshift( $links, $link );
    return $links;
} );

/* ------------------------------------------------------------------ */
/*  Render main page (two tabs: Modules / Instellingen)                */
/* ------------------------------------------------------------------ */

function dp_toolbox_settings_page() {
    if ( ! dp_toolbox_current_user_has_access() ) {
        wp_die( 'Je hebt geen toegang tot deze pagina.' );
    }

    $tab      = isset( $_GET['tab'] ) && $_GET['tab'] === 'admin' ? 'admin' : 'modules';
    $base_url = admin_url( 'admin.php?page=dp-toolbox' );

    // Pre-calculate module counts for header display
    $all_modules  = dp_toolbox_get_available_modules();
    $enabled_mods = dp_toolbox_get_enabled_modules();
    $active_count = count( array_intersect( array_keys( $all_modules ), $enabled_mods ) );
    $total_count  = count( $all_modules );
    ?>
    <div class="wrap dp-toolbox-settings">

        <style>
            .dp-toolbox-settings { max-width: 1100px; }

            .dp-toolbox-header {
                background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%);
                color: #fff;
                padding: 24px 32px 0;
                border-radius: 10px 10px 0 0;
                margin-bottom: 0;
            }
            .dp-toolbox-header h1 { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: #fff; }
            .dp-toolbox-header p  { margin: 0 0 18px; opacity: 0.7; font-size: 13px; }

            .dp-toolbox-tabs {
                display: flex; gap: 0; margin: 0; padding: 0; list-style: none; flex: 1;
            }
            .dp-toolbox-tab-bar {
                display: flex; align-items: flex-end;
            }
            .dp-header-actions {
                display: flex; align-items: center; gap: 14px;
                margin-left: auto; padding: 6px 0 10px;
            }
            .dp-header-counter {
                font-size: 13px; color: rgba(255,255,255,0.6);
            }
            .dp-header-counter strong {
                color: #fff; font-weight: 700; font-size: 15px;
            }
            .dp-header-actions .button-primary {
                background: rgba(255,255,255,0.15) !important; border-color: rgba(255,255,255,0.25) !important;
                color: #fff !important; font-size: 12px !important; padding: 4px 18px !important;
                border-radius: 6px !important; height: auto !important; line-height: 1.6 !important;
            }
            .dp-header-actions .button-primary:hover {
                background: rgba(255,255,255,0.25) !important;
            }
            .dp-toolbox-tabs a {
                display: flex; align-items: center; gap: 6px;
                padding: 10px 22px; color: rgba(255,255,255,0.55); text-decoration: none;
                font-size: 13px; font-weight: 500;
                border-bottom: 3px solid transparent; transition: all 0.2s; white-space: nowrap;
            }
            .dp-toolbox-tabs a:hover { color: rgba(255,255,255,0.85); background: rgba(255,255,255,0.05); }
            .dp-toolbox-tabs a.active { color: #fff; border-bottom-color: #c4b5fd; }
            .dp-toolbox-tabs .dashicons { font-size: 15px; width: 15px; height: 15px; line-height: 15px; }

            .dp-toolbox-content {
                background: #f0f0f1; border-radius: 0 0 10px 10px;
                padding: 24px 32px; border: 1px solid #ddd; border-top: none;
            }

            /* Toggle switch (shared) */
            .dp-toggle input[type="checkbox"] { display: none; }
            .dp-toggle label {
                display: block; width: 42px; height: 22px; background: #ccc;
                border-radius: 11px; position: relative; cursor: pointer; transition: background 0.2s; flex-shrink: 0;
            }
            .dp-toggle label::after {
                content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;
                background: #fff; border-radius: 50%; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            .dp-toggle input:checked + label { background: #281E5D; }
            .dp-toggle input:checked + label::after { transform: translateX(20px); }

            /* Admin settings */
            .dp-admin-section { margin-bottom: 24px; }
            .dp-admin-section h2 {
                font-size: 15px; font-weight: 700; color: #1d2327;
                margin: 0 0 6px; padding-bottom: 8px; border-bottom: 2px solid #281E5D;
            }
            .dp-admin-section p.desc { margin: 0 0 12px; font-size: 13px; color: #666; }

            .dp-role-grid, .dp-user-grid {
                display: flex; flex-direction: column; gap: 6px;
            }
            .dp-role-card, .dp-user-card {
                background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
                padding: 12px 18px; display: flex; align-items: center; gap: 14px;
                transition: border-color 0.2s;
            }
            .dp-role-card:hover, .dp-user-card:hover {
                border-color: #281E5D; box-shadow: 0 1px 6px rgba(40,30,93,0.06);
            }
            .dp-role-card.is-allowed  { border-left: 4px solid #281E5D; }
            .dp-user-card.is-blocked  { border-left: 4px solid #d63638; opacity: 0.6; }
            .dp-user-card.is-blocked:hover { opacity: 1; }

            .dp-role-label, .dp-user-label { flex: 1; font-size: 13px; font-weight: 500; color: #1d2327; }
            .dp-role-slug  { font-size: 11px; color: #999; }
            .dp-user-email { font-size: 12px; color: #999; margin-left: 8px; font-weight: 400; }

            .dp-user-badge {
                font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
                padding: 3px 8px; border-radius: 4px;
            }
            .dp-user-badge.allowed  { color: #281E5D; background: #eee8ff; }
            .dp-user-badge.blocked  { color: #d63638; background: #fce9e9; }

            /* Block toggle: green = access, red = blocked */
            .dp-block-toggle label { background: #00a32a; }
            .dp-block-toggle label::after { transform: translateX(20px); }
            .dp-block-toggle input:checked + label { background: #d63638; }
            .dp-block-toggle input:checked + label::after { transform: translateX(0); }

            /* Buttons */
            .dp-toolbox-settings .submit { margin-top: 8px; }
            .dp-toolbox-settings .button-primary {
                background: #281E5D; border-color: #281E5D; border-radius: 6px;
                padding: 6px 22px; font-size: 13px; height: auto; line-height: 1.6;
            }
            .dp-toolbox-settings .button-primary:hover,
            .dp-toolbox-settings .button-primary:focus { background: #4a3a8a; border-color: #4a3a8a; }
        </style>

        <h1 class="dp-toolbox-notice-anchor" style="margin:0;padding:0;height:0;overflow:hidden;"></h1>

        <div class="dp-toolbox-header">
            <h1><img src="<?php echo esc_url( DP_TOOLBOX_URL . 'assets/dp-icon.png' ); ?>" alt="" style="width:28px;height:28px;vertical-align:middle;margin-right:10px;border-radius:6px;">DP Toolbox</h1>
            <p>Design Pixels gereedschapskist</p>
            <div class="dp-toolbox-tab-bar">
                <nav class="dp-toolbox-tabs">
                    <a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo $tab === 'modules' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-plugins"></span> Modules
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'admin', $base_url ) ); ?>" class="<?php echo $tab === 'admin' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-generic"></span> Instellingen
                    </a>
                </nav>
                <div class="dp-header-actions" id="dp-header-actions">
                        <?php if ( $tab === 'modules' ) : ?>
                            <span class="dp-header-counter"><strong><?php echo $active_count; ?></strong> / <?php echo $total_count; ?> actief</span>
                            <button type="submit" form="dp-modules-form" class="button button-primary">Opslaan</button>
                        <?php endif; ?>
                    </div>
            </div>
        </div>

        <div class="dp-toolbox-content">
            <?php
            if ( $tab === 'admin' ) {
                dp_toolbox_render_admin_tab();
            } else {
                dp_toolbox_render_modules_tab();
            }
            ?>
        </div>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Modules tab                                                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_render_modules_tab() {
    $modules = dp_toolbox_get_available_modules();
    $enabled = dp_toolbox_get_enabled_modules();
    $notices = dp_toolbox_get_module_notices();

    $categories = [
        'dashboard' => [ 'label' => 'Dashboard',          'icon' => 'dashicons-dashboard' ],
        'admin'     => [ 'label' => 'Admin & Navigatie',   'icon' => 'dashicons-admin-generic' ],
        'security'  => [ 'label' => 'Beveiliging',         'icon' => 'dashicons-shield' ],
        'email'     => [ 'label' => 'E-mail',              'icon' => 'dashicons-email-alt' ],
        'media'     => [ 'label' => 'Media',               'icon' => 'dashicons-admin-media' ],
        'tools'     => [ 'label' => 'Tools',               'icon' => 'dashicons-admin-tools' ],
    ];

    $module_categories = [
        'activity-log'      => 'dashboard',
        'quick-setup'       => 'dashboard',
        'dashboard-widgets' => 'dashboard',
        'site-navigator'    => 'admin',
        'role-manager'      => 'admin',
        'menu-sorter'       => 'admin',
        'page-sorter'       => 'admin',
        'plugins-sorter'    => 'admin',
        'branding'          => 'admin',
        'disable-features'  => 'admin',
        'duplicate-post'    => 'admin',
        'security-headers'   => 'security',
        'custom-login-url'   => 'security',
        'login-branding'     => 'security',
        'maintenance-mode'   => 'security',
        'redirects'          => 'security',
        'revision-limiter'   => 'security',
        'smtp'                => 'email',
        'alt-text-filler'     => 'media',
        'media-categories'   => 'media',
        'media-replacement'  => 'media',
        'unused-media'       => 'media',
        'webp-converter'     => 'media',
        'file-manager'         => 'tools',
        'search-replace'       => 'tools',
        'plugin-installer'     => 'tools',
        'thumbnails-manager'   => 'media',
    ];

    $grouped = [];
    foreach ( $modules as $slug => $module ) {
        $cat = $module_categories[ $slug ] ?? 'admin';
        $grouped[ $cat ][ $slug ] = $module;
    }
    foreach ( $grouped as &$group ) {
        uasort( $group, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
    }
    unset( $group );

    $active_count = count( array_intersect( array_keys( $modules ), $enabled ) );
    $total_count  = count( $modules );
    $first_cat    = array_key_first( $categories );
    ?>
    <style>
        /* --- Sidebar layout --- */
        .dp-modules-layout {
            display: flex; gap: 0; min-height: 400px;
            margin: -24px -32px; /* bleed into .dp-toolbox-content padding */
        }

        /* --- Sidebar --- */
        .dp-modules-sidebar {
            width: 240px; flex-shrink: 0;
            background: #fff; border-right: 1px solid #e0e0e0;
            display: flex; flex-direction: column;
            border-radius: 0 0 0 10px;
        }
        .dp-sidebar-nav {
            flex: 1; padding: 16px 0; display: flex; flex-direction: column; gap: 2px;
        }
        .dp-sidebar-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 18px; cursor: pointer;
            font-size: 13px; font-weight: 500; color: #666;
            border-left: 3px solid transparent;
            transition: all 0.15s;
            text-decoration: none;
        }
        .dp-sidebar-item:hover {
            color: #1d2327; background: #f8f7fc;
        }
        .dp-sidebar-item.is-active {
            color: #281E5D; font-weight: 600; background: #f3f0ff;
            border-left-color: #281E5D;
        }
        .dp-sidebar-item .dashicons {
            font-size: 16px; width: 16px; height: 16px; line-height: 16px;
            color: inherit; opacity: 0.6;
        }
        .dp-sidebar-item.is-active .dashicons { opacity: 1; }
        .dp-sidebar-count {
            margin-left: auto; font-size: 11px; color: #aaa;
            background: #f0f0f1; padding: 1px 8px; border-radius: 10px;
        }
        .dp-sidebar-item.is-active .dp-sidebar-count {
            background: #eee8ff; color: #281E5D;
        }

        /* --- Main content --- */
        .dp-modules-main {
            flex: 1; padding: 24px 28px; min-width: 0;
        }
        .dp-cat-panel { display: none; }
        .dp-cat-panel.is-visible { display: block; }

        .dp-cat-panel-header {
            display: flex; align-items: center; gap: 8px;
            margin: 0 0 16px; padding-bottom: 10px;
            border-bottom: 2px solid #281E5D;
        }
        .dp-cat-panel-header .dashicons { color: #281E5D; font-size: 18px; width: 18px; height: 18px; }
        .dp-cat-panel-header h2 { margin: 0; font-size: 15px; font-weight: 700; color: #1d2327; }

        .dp-module-list {
            display: flex; flex-direction: column; gap: 8px;
        }

        .dp-module-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 16px; display: flex; align-items: center; gap: 12px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .dp-module-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-module-card.is-active  { border-left: 3px solid #281E5D; }
        .dp-module-card.is-inactive { opacity: 0.55; }
        .dp-module-card.is-inactive:hover { opacity: 1; }

        .dp-module-info { flex: 1; min-width: 0; }
        .dp-module-info h3 { margin: 0; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-module-info h3 .dp-version { color: #bbb; font-size: 10px; font-weight: 400; margin-left: 4px; }
        .dp-module-info p {
            margin: 3px 0 0; color: #888; font-size: 12px; line-height: 1.5;
        }

        .dp-module-icons {
            display: flex; gap: 6px; align-items: center; flex-shrink: 0;
        }
        .dp-module-tip {
            position: relative; cursor: help;
            color: #bbb; font-size: 16px; line-height: 1;
            transition: color 0.15s;
        }
        .dp-module-tip:hover { color: #281E5D; }
        .dp-module-tip .dashicons { font-size: 16px; width: 16px; height: 16px; }
        .dp-module-tip .dp-module-tip-text {
            display: none; position: absolute; right: -8px; top: 28px; z-index: 10;
            background: #1d2327; color: #fff; font-size: 12px; font-weight: 400;
            padding: 8px 12px; border-radius: 6px; width: 260px; white-space: normal; line-height: 1.5;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .dp-module-tip .dp-module-tip-text::before {
            content: ''; position: absolute; top: -6px; right: 12px;
            border-left: 6px solid transparent; border-right: 6px solid transparent;
            border-bottom: 6px solid #1d2327;
        }
        .dp-module-tip:hover .dp-module-tip-text { display: block; }

        .dp-module-warn {
            position: relative; cursor: help;
            color: #c48a00; font-size: 16px; line-height: 1;
        }
        .dp-module-warn .dashicons { font-size: 16px; width: 16px; height: 16px; }
        .dp-module-warn .dp-module-warn-tip {
            display: none; position: absolute; right: -8px; top: 28px; z-index: 10;
            background: #1d2327; color: #fff; font-size: 12px; font-weight: 400;
            padding: 8px 12px; border-radius: 6px; width: 240px; white-space: normal; line-height: 1.5;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .dp-module-warn .dp-module-warn-tip::before {
            content: ''; position: absolute; top: -6px; right: 12px;
            border-left: 6px solid transparent; border-right: 6px solid transparent;
            border-bottom: 6px solid #1d2327;
        }
        .dp-module-warn:hover .dp-module-warn-tip { display: block; }
    </style>

    <form id="dp-modules-form" method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_settings' ); ?>

        <div class="dp-modules-layout">
            <!-- Sidebar -->
            <div class="dp-modules-sidebar">
                <nav class="dp-sidebar-nav">
                    <?php foreach ( $categories as $cat_key => $cat ) :
                        if ( empty( $grouped[ $cat_key ] ) ) continue;
                        $cat_active = count( array_intersect( array_keys( $grouped[ $cat_key ] ), $enabled ) );
                    ?>
                        <a class="dp-sidebar-item<?php echo $cat_key === $first_cat ? ' is-active' : ''; ?>"
                           href="#<?php echo esc_attr( $cat_key ); ?>"
                           data-cat="<?php echo esc_attr( $cat_key ); ?>">
                            <span class="dashicons <?php echo esc_attr( $cat['icon'] ); ?>"></span>
                            <?php echo esc_html( $cat['label'] ); ?>
                            <span class="dp-sidebar-count"><?php echo $cat_active; ?>/<?php echo count( $grouped[ $cat_key ] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- Module panels -->
            <div class="dp-modules-main">
                <?php foreach ( $categories as $cat_key => $cat ) :
                    if ( empty( $grouped[ $cat_key ] ) ) continue;
                    $cat_modules = $grouped[ $cat_key ];
                ?>
                    <div class="dp-cat-panel<?php echo $cat_key === $first_cat ? ' is-visible' : ''; ?>"
                         data-category="<?php echo esc_attr( $cat_key ); ?>">
                        <div class="dp-cat-panel-header">
                            <span class="dashicons <?php echo esc_attr( $cat['icon'] ); ?>"></span>
                            <h2><?php echo esc_html( $cat['label'] ); ?></h2>
                        </div>
                        <div class="dp-module-list">
                            <?php foreach ( $cat_modules as $slug => $module ) :
                                $is_active  = in_array( $slug, $enabled, true );
                                $has_notice = isset( $notices[ $slug ] );
                            ?>
                                <div class="dp-module-card <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>">
                                    <div class="dp-toggle">
                                        <input type="checkbox"
                                               id="dp-module-<?php echo esc_attr( $slug ); ?>"
                                               name="dp_toolbox_enabled_modules[]"
                                               value="<?php echo esc_attr( $slug ); ?>"
                                               <?php checked( $is_active ); ?>>
                                        <label for="dp-module-<?php echo esc_attr( $slug ); ?>"></label>
                                    </div>
                                    <div class="dp-module-info">
                                        <h3>
                                            <?php echo esc_html( $module['name'] ); ?>
                                            <span class="dp-version">v<?php echo esc_html( $module['version'] ); ?></span>
                                        </h3>
                                    </div>
                                    <div class="dp-module-icons">
                                        <?php if ( $has_notice ) : ?>
                                            <span class="dp-module-warn">
                                                <span class="dashicons dashicons-warning"></span>
                                                <span class="dp-module-warn-tip"><?php echo esc_html( $notices[ $slug ] ); ?></span>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $module['description'] ) ) : ?>
                                            <span class="dp-module-tip">
                                                <span class="dashicons dashicons-info-outline"></span>
                                                <span class="dp-module-tip-text"><?php echo esc_html( $module['description'] ); ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <script>
    (function(){
        var items  = document.querySelectorAll('.dp-sidebar-item');
        var panels = document.querySelectorAll('.dp-cat-panel');

        function activate(cat) {
            items.forEach(function(el)  { el.classList.toggle('is-active', el.dataset.cat === cat); });
            panels.forEach(function(el) { el.classList.toggle('is-visible', el.dataset.category === cat); });
        }

        items.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                activate(this.dataset.cat);
                history.replaceState(null, '', this.getAttribute('href'));
            });
        });

        // Restore from URL hash
        var hash = location.hash.replace('#', '');
        if (hash && document.querySelector('[data-cat="' + hash + '"]')) {
            activate(hash);
        }
    })();
    </script>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Admin settings tab                                                 */
/* ------------------------------------------------------------------ */

function dp_toolbox_render_admin_tab() {
    $allowed_roles = (array) get_option( 'dp_toolbox_allowed_roles', [ 'administrator' ] );
    $blocked_users = array_map( 'strval', (array) get_option( 'dp_toolbox_blocked_users', [] ) );
    $all_roles     = wp_roles()->roles;

    // Get all admin users (users who have any of the allowed roles)
    $admin_users = get_users( [ 'role__in' => [ 'administrator' ], 'orderby' => 'display_name' ] );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_admin_settings' ); ?>

        <div class="dp-admin-section">
            <h2>Gebruikersrollen</h2>
            <p class="desc">Selecteer welke rollen DP Toolbox mogen zien en gebruiken.</p>
            <div class="dp-role-grid">
                <?php foreach ( $all_roles as $role_slug => $role ) :
                    $is_allowed = in_array( $role_slug, $allowed_roles, true );
                ?>
                    <div class="dp-role-card <?php echo $is_allowed ? 'is-allowed' : ''; ?>">
                        <div class="dp-toggle">
                            <input type="checkbox"
                                   id="dp-role-<?php echo esc_attr( $role_slug ); ?>"
                                   name="dp_toolbox_allowed_roles[]"
                                   value="<?php echo esc_attr( $role_slug ); ?>"
                                   <?php checked( $is_allowed ); ?>>
                            <label for="dp-role-<?php echo esc_attr( $role_slug ); ?>"></label>
                        </div>
                        <span class="dp-role-label">
                            <?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
                            <span class="dp-role-slug">(<?php echo esc_html( $role_slug ); ?>)</span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dp-admin-section">
            <h2>Administrators</h2>
            <p class="desc">Blokkeer specifieke administrators. Geblokkeerde admins zien DP Toolbox niet, ook als hun rol toegang heeft.</p>
            <div class="dp-user-grid">
                <?php if ( empty( $admin_users ) ) : ?>
                    <p style="color:#999;font-size:13px;">Geen administrators gevonden.</p>
                <?php else : ?>
                    <?php foreach ( $admin_users as $user ) :
                        $is_blocked = in_array( (string) $user->ID, $blocked_users, true );
                        $is_current = ( $user->ID === get_current_user_id() );
                    ?>
                        <div class="dp-user-card <?php echo $is_blocked ? 'is-blocked' : ''; ?>">
                            <div class="dp-toggle dp-block-toggle">
                                <input type="checkbox"
                                       id="dp-user-<?php echo esc_attr( $user->ID ); ?>"
                                       name="dp_toolbox_blocked_users[]"
                                       value="<?php echo esc_attr( $user->ID ); ?>"
                                       <?php checked( $is_blocked ); ?>
                                       <?php echo $is_current ? 'disabled' : ''; ?>>
                                <label for="dp-user-<?php echo esc_attr( $user->ID ); ?>"
                                       <?php echo $is_current ? 'title="Je kunt jezelf niet blokkeren"' : ''; ?>></label>
                            </div>
                            <span class="dp-user-label">
                                <?php echo esc_html( $user->display_name ); ?>
                                <span class="dp-user-email"><?php echo esc_html( $user->user_email ); ?></span>
                            </span>
                            <?php if ( $is_current ) : ?>
                                <span class="dp-user-badge allowed">Jij</span>
                            <?php elseif ( $is_blocked ) : ?>
                                <span class="dp-user-badge blocked">Geblokkeerd</span>
                            <?php else : ?>
                                <span class="dp-user-badge allowed">Toegang</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php submit_button( 'Opslaan' ); ?>
    </form>
    <?php
}
