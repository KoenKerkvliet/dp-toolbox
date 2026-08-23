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

    // Plugin is alleen zichtbaar voor @designpixels.nl users.
    if ( ! dp_toolbox_is_dp_user( $user->ID ) ) return false;

    // DP-staff (@designpixels.nl) heeft ALTIJD toegang — bypass role + block checks.
    // Voorkomt lock-out wanneer per ongeluk 'administrator' uit allowed_roles wordt
    // verwijderd of een DP-user in blocked_users belandt.
    return true;
}

/* ------------------------------------------------------------------ */
/*  Register settings                                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_settings', 'dp_toolbox_enabled_modules', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            $input = is_array( $input ) ? array_map( 'sanitize_key', $input ) : [];
            // Harde gate: een module met een onvervulde vereiste kan nooit
            // aangezet worden, ook niet door een geknutselde POST.
            return array_values( array_filter( $input, 'dp_toolbox_module_requirement_met' ) );
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
    register_setting( 'dp_toolbox_admin_settings', 'dp_toolbox_branding_mode', [
        'type'              => 'string',
        'sanitize_callback' => function ( $input ) {
            return in_array( $input, [ 'client', 'dp' ], true ) ? $input : 'dp';
        },
        'default' => 'dp',
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

    $tab_param = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    $tab       = in_array( $tab_param, [ 'admin', 'checklist' ], true ) ? $tab_param : 'modules';
    $base_url  = admin_url( 'admin.php?page=dp-toolbox' );

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
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'checklist', $base_url ) ); ?>" class="<?php echo $tab === 'checklist' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-yes-alt"></span> Checklist
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
            } elseif ( $tab === 'checklist' ) {
                dp_toolbox_render_checklist_tab();
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
    $modules      = dp_toolbox_get_available_modules();
    $enabled      = dp_toolbox_get_enabled_modules();
    $notices      = dp_toolbox_get_module_notices();
    $requirements = dp_toolbox_get_module_requirements();

    /*
     * De categorie staat in de module-header ("Category: media"), niet in een
     * lijst hier. Zo kan hij niet vergeten worden bij het toevoegen van een
     * module — dat gebeurde eerder wél, en zulke modules verdwenen dan stil in
     * de grootste categorie.
     */
    $categories = [
        'dashboard'   => [ 'label' => 'Dashboard',           'icon' => 'dashicons-dashboard' ],
        'admin'       => [ 'label' => 'Admin',               'icon' => 'dashicons-admin-generic' ],
        'appearance'  => [ 'label' => 'Uiterlijk',           'icon' => 'dashicons-art' ],
        'users'       => [ 'label' => 'Gebruikers',          'icon' => 'dashicons-groups' ],
        'ordering'    => [ 'label' => 'Ordenen',             'icon' => 'dashicons-sort' ],
        'content'     => [ 'label' => 'Content & SEO',       'icon' => 'dashicons-media-text' ],
        'media'       => [ 'label' => 'Media',               'icon' => 'dashicons-admin-media' ],
        'security'    => [ 'label' => 'Beveiliging',         'icon' => 'dashicons-shield' ],
        'tools'       => [ 'label' => 'Tools',               'icon' => 'dashicons-admin-tools' ],
        'woocommerce' => [ 'label' => 'WooCommerce',         'icon' => 'dashicons-cart' ],
        'other'       => [ 'label' => 'Overig',              'icon' => 'dashicons-marker' ],
    ];

    $grouped = [];
    foreach ( $modules as $slug => $module ) {
        $cat = $module['category'] ?? '';
        // Onbekende of ontbrekende categorie belandt zichtbaar in "Overig",
        // niet stilzwijgend tussen de rest.
        if ( ! isset( $categories[ $cat ] ) ) {
            $cat = 'other';
        }
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

        /* Module die op deze site niet aangezet kan worden (vereiste niet vervuld) */
        .dp-module-card.is-blocked { opacity: 1; background: #fafafa; border-style: dashed; }
        .dp-module-card.is-blocked:hover { border-color: #e0e0e0; box-shadow: none; }
        .dp-module-card.is-blocked .dp-toggle { opacity: 0.35; pointer-events: none; }
        .dp-module-card.is-blocked h3 { color: #777; }
        .dp-module-blocked {
            margin: 4px 0 0; font-size: 12px; line-height: 1.4; color: #8a6d1f;
        }

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

        /* Settings (gear) button — only on active modules with inline settings */
        .dp-module-settings-btn {
            background: none; border: 1px solid transparent; border-radius: 4px;
            color: #bbb; cursor: pointer; padding: 2px 4px; line-height: 1;
            display: inline-flex; align-items: center; transition: all 0.15s;
        }
        .dp-module-settings-btn:hover {
            color: #281E5D; border-color: #d8d3eb; background: #f5f3fb;
        }
        .dp-module-settings-btn.is-open {
            color: #281E5D; background: #eee8ff; border-color: #c4b5fd;
        }
        .dp-module-settings-btn .dashicons { font-size: 16px; width: 16px; height: 16px; }

        /* Inline settings panel container (under modules layout) */
        .dp-inline-settings-wrap {
            margin: 24px -32px -24px;  /* bleed into .dp-toolbox-content padding */
            padding: 0;
            border-top: 1px solid #e0e0e0;
            background: #fff;
            border-radius: 0 0 10px 10px;
            display: none;
        }
        .dp-inline-settings-wrap.is-open { display: block; }
        .dp-inline-panel {
            display: none;
            padding: 22px 32px;
        }
        .dp-inline-panel.is-visible { display: block; }
        .dp-inline-panel-header {
            display: flex; align-items: center; gap: 10px;
            margin: 0 0 16px; padding-bottom: 12px;
            border-bottom: 2px solid #281E5D;
        }
        .dp-inline-panel-header h2 {
            margin: 0; font-size: 16px; font-weight: 700; color: #1d2327; flex: 1;
        }
        .dp-inline-panel-header .dp-inline-panel-desc {
            font-size: 12px; color: #888; font-weight: 400;
            margin-left: 8px;
        }
        .dp-inline-panel-close {
            background: none; border: 1px solid #ddd; border-radius: 4px;
            color: #888; cursor: pointer; padding: 3px 9px; font-size: 16px;
            line-height: 1; transition: all 0.15s;
        }
        .dp-inline-panel-close:hover {
            border-color: #d63638; color: #d63638;
        }
        /* Reset module-page styles that fight with inline context */
        .dp-inline-panel .dp-page-wrap { max-width: none; padding: 0; margin: 0; }
        .dp-inline-panel .dp-page-header,
        .dp-inline-panel .dp-page-content { background: transparent; padding: 0; border: none; border-radius: 0; }
        .dp-inline-panel .dp-page-header { display: none; }

        /* --- Zoeken --- */
        .dp-module-search { position: relative; padding: 12px; border-bottom: 1px solid #e0e0e0; }
        .dp-module-search .dashicons {
            position: absolute; left: 21px; top: 50%; transform: translateY(-50%);
            color: #8c8f94; font-size: 17px; width: 17px; height: 17px; pointer-events: none;
        }
        .dp-module-search input {
            width: 100%; padding: 6px 8px 6px 30px; border: 1px solid #dcdcde; border-radius: 4px;
            font-size: 13px; line-height: 1.5; box-shadow: none;
        }
        .dp-module-search input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }

        /* Tijdens het zoeken tonen we alle categorieën onder elkaar, met een
           kopje per categorie, zodat je meteen ziet waar een module thuishoort. */
        .dp-modules-main.is-searching .dp-cat-panel { display: block; }
        .dp-modules-main.is-searching .dp-cat-panel.is-empty { display: none; }
        .dp-modules-main.is-searching .dp-module-card.is-hidden { display: none; }
        .dp-search-empty {
            display: none; padding: 40px 24px; text-align: center; color: #646970; font-size: 14px;
        }
        .dp-modules-main.is-searching.has-no-results .dp-search-empty { display: block; }
    </style>

    <form id="dp-modules-form" method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_settings' ); ?>

        <div class="dp-modules-layout">
            <!-- Sidebar -->
            <div class="dp-modules-sidebar">
                <div class="dp-module-search">
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" id="dp-module-search" placeholder="Zoek een module&hellip;"
                           autocomplete="off" aria-label="Zoek een module">
                </div>
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
                                $req        = $requirements[ $slug ] ?? null;
                                $blocked    = $req && empty( $req['met'] );
                            ?>
                                <div class="dp-module-card <?php echo $is_active ? 'is-active' : 'is-inactive'; ?><?php echo $blocked ? ' is-blocked' : ''; ?>"
                                     data-zoek="<?php echo esc_attr( strtolower( $module['name'] . ' ' . $module['description'] . ' ' . $slug ) ); ?>">
                                    <div class="dp-toggle">
                                        <input type="checkbox"
                                               id="dp-module-<?php echo esc_attr( $slug ); ?>"
                                               name="dp_toolbox_enabled_modules[]"
                                               value="<?php echo esc_attr( $slug ); ?>"
                                               <?php checked( $is_active && ! $blocked ); ?>
                                               <?php disabled( $blocked ); ?>>
                                        <label for="dp-module-<?php echo esc_attr( $slug ); ?>"></label>
                                    </div>
                                    <div class="dp-module-info">
                                        <h3>
                                            <?php echo esc_html( $module['name'] ); ?>
                                            <span class="dp-version">v<?php echo esc_html( $module['version'] ); ?></span>
                                        </h3>
                                        <?php if ( $blocked ) : ?>
                                            <p class="dp-module-blocked"><?php echo esc_html( $req['reason'] ); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="dp-module-icons">
                                        <?php if ( $is_active && dp_toolbox_has_inline_settings( $slug ) ) : ?>
                                            <button type="button"
                                                    class="dp-module-settings-btn"
                                                    data-module="<?php echo esc_attr( $slug ); ?>"
                                                    title="Instellingen">
                                                <span class="dashicons dashicons-admin-generic"></span>
                                            </button>
                                        <?php endif; ?>
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
                <p class="dp-search-empty">Geen module gevonden. Probeer een ander zoekwoord.</p>
            </div>
        </div>
    </form>

    <?php
    /* ----------------------------------------------------------------- */
    /*  Inline settings panels — pre-rendered, hidden by default          */
    /* ----------------------------------------------------------------- */
    $inline_settings = dp_toolbox_get_inline_settings();
    // Filter: only render panels for modules that are currently enabled
    $inline_settings = array_filter( $inline_settings, function ( $cfg, $slug ) use ( $enabled ) {
        return in_array( $slug, $enabled, true ) && is_callable( $cfg['callback'] );
    }, ARRAY_FILTER_USE_BOTH );
    ?>
    <?php if ( ! empty( $inline_settings ) ) : ?>
        <div class="dp-inline-settings-wrap" id="dp-inline-settings-wrap">
            <?php foreach ( $inline_settings as $slug => $cfg ) : ?>
                <div class="dp-inline-panel"
                     data-inline-module="<?php echo esc_attr( $slug ); ?>"
                     id="settings-<?php echo esc_attr( $slug ); ?>">
                    <div class="dp-inline-panel-header">
                        <h2><?php echo esc_html( $cfg['title'] ?: $slug ); ?></h2>
                        <?php if ( ! empty( $cfg['description'] ) ) : ?>
                            <span class="dp-inline-panel-desc"><?php echo esc_html( $cfg['description'] ); ?></span>
                        <?php endif; ?>
                        <button type="button" class="dp-inline-panel-close" title="Sluiten">×</button>
                    </div>
                    <div class="dp-inline-panel-body">
                        <?php call_user_func( $cfg['callback'] ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
    (function(){
        var items  = document.querySelectorAll('.dp-sidebar-item');
        var panels = document.querySelectorAll('.dp-cat-panel');

        function activate(cat) {
            items.forEach(function(el)  { el.classList.toggle('is-active', el.dataset.cat === cat); });
            panels.forEach(function(el) { el.classList.toggle('is-visible', el.dataset.category === cat); });
            // Onthoud de actieve categorie, zodat we na een opslaan-redirect
            // (options.php dropt de #hash) in dezelfde tab blijven.
            try { sessionStorage.setItem('dp_toolbox_active_cat', cat); } catch (e) {}
        }

        items.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                activate(this.dataset.cat);
                // Preserve any settings-hash if open, otherwise just write the cat hash
                var openSlug = sessionStorage.getItem('dp_toolbox_open_panel');
                history.replaceState(null, '', openSlug ? '#settings-' + openSlug : this.getAttribute('href'));
            });
        });

        /* --- Zoeken over alle categorieën heen --- */
        var zoekveld = document.getElementById('dp-module-search');
        var main     = document.querySelector('.dp-modules-main');
        var kaarten  = document.querySelectorAll('.dp-module-card');

        function zoek(term) {
            term = (term || '').trim().toLowerCase();

            if (!term) {
                // Terug naar normaal: alles zichtbaar, actieve categorie herstellen.
                main.classList.remove('is-searching', 'has-no-results');
                kaarten.forEach(function(k) { k.classList.remove('is-hidden'); });
                panels.forEach(function(p) { p.classList.remove('is-empty'); });
                var actief = document.querySelector('.dp-sidebar-item.is-active');
                if (actief) activate(actief.dataset.cat);
                return;
            }

            main.classList.add('is-searching');
            var totaal = 0;

            panels.forEach(function(panel) {
                var raak = 0;
                panel.querySelectorAll('.dp-module-card').forEach(function(kaart) {
                    var match = (kaart.dataset.zoek || '').indexOf(term) !== -1;
                    kaart.classList.toggle('is-hidden', !match);
                    if (match) raak++;
                });
                panel.classList.toggle('is-empty', raak === 0);
                totaal += raak;
            });

            main.classList.toggle('has-no-results', totaal === 0);
        }

        if (zoekveld) {
            zoekveld.addEventListener('input', function() { zoek(this.value); });
            zoekveld.addEventListener('keydown', function(e) {
                // Escape wist het veld en zet de weergave terug.
                if (e.key === 'Escape') { this.value = ''; zoek(''); return; }
                // Enter zou anders het omliggende formulier opslaan — dit veld
                // filtert alleen, het hoort niets te verzenden.
                if (e.key === 'Enter') { e.preventDefault(); }
            });
            // Een categorie aanklikken tijdens het zoeken wist de zoekterm,
            // anders klik je op iets dat niets lijkt te doen.
            items.forEach(function(item) {
                item.addEventListener('click', function() {
                    if (zoekveld.value) { zoekveld.value = ''; zoek(''); }
                });
            });
        }

        /* --- Inline settings panels --- */
        var inlineWrap   = document.getElementById('dp-inline-settings-wrap');
        var inlinePanels = document.querySelectorAll('.dp-inline-panel');
        var gearBtns     = document.querySelectorAll('.dp-module-settings-btn');

        function openInlinePanel(slug) {
            if (!inlineWrap) return;
            var target = document.querySelector('[data-inline-module="' + slug + '"]');
            if (!target) return;

            inlinePanels.forEach(function(p) { p.classList.remove('is-visible'); });
            gearBtns.forEach(function(b) { b.classList.toggle('is-open', b.dataset.module === slug); });

            target.classList.add('is-visible');
            inlineWrap.classList.add('is-open');
            sessionStorage.setItem('dp_toolbox_open_panel', slug);
            history.replaceState(null, '', '#settings-' + slug);

            // Smooth-scroll AFTER the wrap becomes visible (next frame)
            requestAnimationFrame(function () {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        function closeInlinePanel() {
            if (!inlineWrap) return;
            inlinePanels.forEach(function(p) { p.classList.remove('is-visible'); });
            gearBtns.forEach(function(b) { b.classList.remove('is-open'); });
            inlineWrap.classList.remove('is-open');
            sessionStorage.removeItem('dp_toolbox_open_panel');
            // Strip the #settings-... hash but keep current category if any
            var activeCat = document.querySelector('.dp-sidebar-item.is-active');
            history.replaceState(null, '', activeCat ? activeCat.getAttribute('href') : location.pathname + location.search);
        }

        gearBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var slug = this.dataset.module;
                if (this.classList.contains('is-open')) {
                    closeInlinePanel();
                } else {
                    openInlinePanel(slug);
                }
            });
        });

        document.querySelectorAll('.dp-inline-panel-close').forEach(function(btn) {
            btn.addEventListener('click', closeInlinePanel);
        });

        /* --- Restore state from URL hash or sessionStorage --- */
        var hash = location.hash.replace('#', '');
        if (hash.indexOf('settings-') === 0) {
            var slug = hash.replace('settings-', '');
            // First activate the category that contains this module so the gear is reachable
            var btn = document.querySelector('.dp-module-settings-btn[data-module="' + slug + '"]');
            if (btn) {
                var card = btn.closest('.dp-cat-panel');
                if (card && card.dataset.category) activate(card.dataset.category);
                openInlinePanel(slug);
            }
        } else if (hash && document.querySelector('[data-cat="' + hash + '"]')) {
            activate(hash);
            // Re-open last panel from sessionStorage (within current cat)
            var lastSlug = sessionStorage.getItem('dp_toolbox_open_panel');
            if (lastSlug && document.querySelector('.dp-module-settings-btn[data-module="' + lastSlug + '"]')) {
                openInlinePanel(lastSlug);
            }
        } else {
            // Geen categorie-hash in de URL (o.a. ná een instellingen-opslag-redirect):
            // herstel de laatst-actieve categorie zodat je in dezelfde tab blijft.
            var storedCat = sessionStorage.getItem('dp_toolbox_active_cat');
            if (storedCat && document.querySelector('[data-cat="' + storedCat + '"]')) {
                activate(storedCat);
            }
            // Re-open last panel from sessionStorage
            var lastSlug = sessionStorage.getItem('dp_toolbox_open_panel');
            if (lastSlug && document.querySelector('.dp-module-settings-btn[data-module="' + lastSlug + '"]')) {
                openInlinePanel(lastSlug);
            }
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

    // Get all admin users (users who have any of the allowed roles).
    // DP-staff (@designpixels.nl) wordt eruit gefilterd — die hebben altijd toegang
    // en zijn niet blokkeerbaar via de UI.
    $admin_users = get_users( [ 'role__in' => [ 'administrator' ], 'orderby' => 'display_name' ] );
    $admin_users = array_values( array_filter( $admin_users, function ( $u ) {
        return ! dp_toolbox_is_dp_user( $u->ID );
    } ) );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_admin_settings' ); ?>

        <?php
        $branding_mode = dp_toolbox_branding_mode();
        $branding_logo = dp_toolbox_branding_logo_url();
        ?>
        <div class="dp-admin-section">
            <h2>Branding</h2>
            <p class="desc">Bepaalt welk logo en welke kleuren bezoekers te zien krijgen op de onderhoudspagina en de inlogpagina.</p>

            <style>
                .dp-brand-grid { display: flex; gap: 12px; flex-wrap: wrap; }
                .dp-brand-opt { flex: 1; min-width: 240px; cursor: pointer; }
                .dp-brand-opt input { position: absolute; opacity: 0; pointer-events: none; }
                .dp-brand-card {
                    background: #fff; border: 2px solid #e0e0e0; border-radius: 8px;
                    padding: 16px 18px; transition: border-color 0.2s, box-shadow 0.2s; height: 100%;
                }
                .dp-brand-opt input:checked + .dp-brand-card {
                    border-color: #281E5D; box-shadow: 0 2px 10px rgba(40,30,93,0.12);
                }
                .dp-brand-card h3 { margin: 0 0 4px; font-size: 13px; font-weight: 600; color: #1d2327; }
                .dp-brand-card p { margin: 0 0 12px; font-size: 12px; line-height: 1.5; color: #666; }
                .dp-brand-swatch { height: 34px; border-radius: 5px; display: flex; align-items: center;
                    justify-content: center; overflow: hidden; }
                .dp-brand-swatch img { max-height: 22px; max-width: 70%; object-fit: contain; }
                .dp-brand-swatch span { font-size: 10px; font-weight: 600; letter-spacing: 0.06em;
                    text-transform: uppercase; color: rgba(255,255,255,0.8); }
                .dp-brand-warn { margin: 10px 0 0; font-size: 12px; color: #8a6d1a; background: #fcf9e8;
                    border-left: 3px solid #dba617; border-radius: 0 5px 5px 0; padding: 8px 10px; }
            </style>

            <div class="dp-brand-grid">
                <label class="dp-brand-opt">
                    <input type="radio" name="dp_toolbox_branding_mode" value="client" <?php checked( $branding_mode, 'client' ); ?>>
                    <div class="dp-brand-card">
                        <h3>Branding van de site</h3>
                        <p>Het logo van de site zelf, met neutrale kleuren. Voor klantsites.</p>
                        <div class="dp-brand-swatch" style="background: linear-gradient(135deg, #1d2327 0%, #2c3338 40%, #3c434a 100%);">
                            <?php
                            $client_logo_id = (int) get_theme_mod( 'custom_logo' );
                            $client_logo    = $client_logo_id ? wp_get_attachment_image_url( $client_logo_id, 'full' ) : get_site_icon_url( 512 );
                            ?>
                            <?php if ( $client_logo ) : ?>
                                <img src="<?php echo esc_url( $client_logo ); ?>" alt="">
                            <?php else : ?>
                                <span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </label>

                <label class="dp-brand-opt">
                    <input type="radio" name="dp_toolbox_branding_mode" value="dp" <?php checked( $branding_mode, 'dp' ); ?>>
                    <div class="dp-brand-card">
                        <h3>Design Pixels</h3>
                        <p>Jouw logo en huisstijl, met een link naar designpixels.nl onderaan.</p>
                        <div class="dp-brand-swatch" style="background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%);">
                            <img src="<?php echo esc_url( DP_TOOLBOX_URL . 'assets/dp-logo.webp' ); ?>" alt="">
                        </div>
                    </div>
                </label>
            </div>

            <?php if ( 'client' === $branding_mode && ! $branding_logo ) : ?>
                <p class="dp-brand-warn">
                    Deze site heeft nog geen logo of site-icoon ingesteld. Zolang dat zo is, tonen de
                    pagina's de sitenaam als tekst.
                </p>
            <?php endif; ?>
        </div>

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
            <p class="desc">Blokkeer specifieke administrators. Geblokkeerde admins zien DP Toolbox niet, ook als hun rol toegang heeft. Design Pixels-staff (@designpixels.nl) heeft altijd toegang en wordt hier niet getoond.</p>
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
    /* ------------------------------------------------------------------ */
    /*  Import / Export sectie                                             */
    /* ------------------------------------------------------------------ */

    // Flash-meldingen na redirect
    if ( ! empty( $_GET['ie_imported'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="margin:16px 0;"><p>' . esc_html( rawurldecode( wp_unslash( $_GET['ie_imported'] ) ) ) . '</p></div>';
    }
    if ( ! empty( $_GET['ie_error'] ) ) {
        $err_map = [
            'no_categories' => 'Selecteer minstens één categorie om te exporteren.',
            'no_file'       => 'Geen bestand geselecteerd.',
            'read_failed'   => 'Bestand kon niet gelezen worden.',
            'invalid_json'  => 'Ongeldig JSON-bestand.',
        ];
        $raw = rawurldecode( wp_unslash( $_GET['ie_error'] ) );
        $msg = $err_map[ $raw ] ?? $raw;
        echo '<div class="notice notice-error is-dismissible" style="margin:16px 0;"><p>' . esc_html( $msg ) . '</p></div>';
    }

    $ie_categories = dp_toolbox_ie_get_categories();
    ?>

    <style>
        .dp-ie-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 28px;
        }
        .dp-ie-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 22px 24px;
        }
        .dp-ie-card h3 {
            margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #1d2327;
            display: flex; align-items: center; gap: 8px;
            padding-bottom: 10px; border-bottom: 2px solid #281E5D;
        }
        .dp-ie-card h3 .dashicons { color: #281E5D; font-size: 18px; width: 18px; height: 18px; }
        .dp-ie-card .desc { margin: 10px 0 16px; color: #666; font-size: 13px; line-height: 1.5; }
        .dp-ie-cats { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        .dp-ie-cat {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 12px; background: #f9f8fc;
            border: 1px solid #efecf6; border-radius: 6px;
            cursor: pointer; transition: border-color 0.15s;
        }
        .dp-ie-cat:hover { border-color: #c4b5fd; }
        .dp-ie-cat input { margin-top: 2px; flex-shrink: 0; }
        .dp-ie-cat-label { font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-ie-cat-desc { font-size: 11px; color: #888; margin-top: 2px; line-height: 1.4; }
        .dp-ie-actions {
            display: flex; gap: 10px; align-items: center; margin-top: 16px;
            padding-top: 14px; border-top: 1px solid #efecf6;
        }
        .dp-ie-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 9px 22px; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background 0.15s;
        }
        .dp-ie-btn:hover { background: #4a3a8a; }
        .dp-ie-btn-secondary {
            background: #fff; color: #281E5D; border: 1px solid #ddd;
            padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 500;
            cursor: pointer; transition: border-color 0.15s;
        }
        .dp-ie-btn-secondary:hover { border-color: #281E5D; }
        .dp-ie-file-input {
            display: block; margin-bottom: 14px;
            padding: 8px; background: #f9f8fc; border: 1px dashed #d0c8e0;
            border-radius: 6px; font-size: 13px; width: 100%;
        }
        .dp-ie-warning {
            background: #fef9ee; border: 1px solid #f0e0b8; border-radius: 6px;
            padding: 10px 14px; font-size: 12px; color: #78350f; line-height: 1.5;
            margin-top: 12px;
        }
        .dp-ie-warning strong { color: #92400e; }
        @media (max-width: 900px) {
            .dp-ie-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="dp-admin-section" style="margin-top: 32px;">
        <h2>Import / Export</h2>
        <p class="desc">Exporteer je instellingen naar een JSON-bestand, of importeer een export om een nieuwe site snel op te zetten.</p>

        <div class="dp-ie-grid">

            <!-- EXPORT -->
            <div class="dp-ie-card">
                <h3><span class="dashicons dashicons-download"></span> Exporteren</h3>
                <p class="desc">Kies welke categorieën je wilt opnemen in het export-bestand.</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="dp_toolbox_ie_export">
                    <?php wp_nonce_field( 'dp_toolbox_ie_export' ); ?>

                    <div class="dp-ie-cats">
                        <?php foreach ( $ie_categories as $key => $cat ) : ?>
                            <label class="dp-ie-cat">
                                <input type="checkbox" name="categories[]" value="<?php echo esc_attr( $key ); ?>" checked>
                                <div>
                                    <div class="dp-ie-cat-label"><?php echo esc_html( $cat['label'] ); ?></div>
                                    <div class="dp-ie-cat-desc"><?php echo esc_html( $cat['desc'] ); ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="dp-ie-actions">
                        <button type="submit" class="dp-ie-btn">
                            <span class="dashicons dashicons-download" style="vertical-align:text-top;font-size:14px;width:14px;height:14px;"></span>
                            Download export
                        </button>
                        <button type="button" class="dp-ie-btn-secondary" onclick="dpIeToggleAll(this.form, true)">Alles selecteren</button>
                        <button type="button" class="dp-ie-btn-secondary" onclick="dpIeToggleAll(this.form, false)">Niets selecteren</button>
                    </div>
                </form>
            </div>

            <!-- IMPORT -->
            <div class="dp-ie-card">
                <h3><span class="dashicons dashicons-upload"></span> Importeren</h3>
                <p class="desc">Upload een DP Toolbox export-bestand. Bestaande instellingen worden overschreven voor de gekozen categorieën.</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="dp_toolbox_ie_import">
                    <?php wp_nonce_field( 'dp_toolbox_ie_import' ); ?>

                    <input type="file" name="import_file" accept="application/json,.json" class="dp-ie-file-input" required>

                    <div class="dp-ie-cats">
                        <?php foreach ( $ie_categories as $key => $cat ) : ?>
                            <label class="dp-ie-cat">
                                <input type="checkbox" name="categories[]" value="<?php echo esc_attr( $key ); ?>" checked>
                                <div>
                                    <div class="dp-ie-cat-label"><?php echo esc_html( $cat['label'] ); ?></div>
                                    <div class="dp-ie-cat-desc">Alleen importeren als aangevinkt.</div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="dp-ie-warning">
                        <strong>Let op:</strong> deze actie overschrijft bestaande instellingen voor de gekozen categorieën. Wachtwoorden (SMTP), API-keys, redirects en per-user rules worden nooit geïmporteerd — die blijven site-specifiek.
                    </div>

                    <div class="dp-ie-actions">
                        <button type="submit" class="dp-ie-btn">
                            <span class="dashicons dashicons-upload" style="vertical-align:text-top;font-size:14px;width:14px;height:14px;"></span>
                            Importeren
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script>
    function dpIeToggleAll(form, checked) {
        form.querySelectorAll('input[type="checkbox"][name="categories[]"]').forEach(function(cb) {
            cb.checked = checked;
        });
    }
    </script>
    <?php
}
