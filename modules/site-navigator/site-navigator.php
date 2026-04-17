<?php
/**
 * Module Name: Site Navigator
 * Description: Voegt een snelnavigatiemenu toe aan de admin bar voor Bricks Builder.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if Bricks theme is active and available.
 */
function dp_toolbox_site_nav_is_bricks_active() {
    return defined( 'BRICKS_VERSION' ) || class_exists( 'Bricks\Theme' );
}

/**
 * Check if current user can use the Bricks builder.
 */
function dp_toolbox_site_nav_user_can_build() {
    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        return false;
    }

    if ( ! dp_toolbox_site_nav_is_bricks_active() ) {
        return false;
    }

    if ( class_exists( 'Bricks\Capabilities\Builder_Permissions' ) ) {
        return \Bricks\Capabilities\Builder_Permissions::current_user_can_use_builder();
    }

    if ( class_exists( 'Bricks\Capabilities' ) && method_exists( 'Bricks\Capabilities', 'current_user_can_use_builder' ) ) {
        return \Bricks\Capabilities::current_user_can_use_builder();
    }

    return current_user_can( 'manage_options' );
}

/**
 * Enqueue admin bar CSS.
 */
add_action( 'wp_enqueue_scripts', 'dp_toolbox_site_nav_enqueue_assets' );
add_action( 'admin_enqueue_scripts', 'dp_toolbox_site_nav_enqueue_assets' );

function dp_toolbox_site_nav_enqueue_assets() {
    if ( ! dp_toolbox_site_nav_user_can_build() ) {
        return;
    }

    wp_enqueue_style(
        'dp-toolbox-site-navigator',
        DP_TOOLBOX_URL . 'modules/site-navigator/assets/style.css',
        [],
        DP_TOOLBOX_VERSION
    );
}

/**
 * Build the admin bar menu — hierarchical with flyouts.
 */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    if ( ! dp_toolbox_site_nav_user_can_build() ) {
        return;
    }

    $icon_url = DP_TOOLBOX_URL . 'assets/dp-icon.png';

    // Top-level menu item.
    $wp_admin_bar->add_node( [
        'id'    => 'dp-site-nav',
        'title' => '<img src="' . esc_url( $icon_url ) . '" alt="" style="height:18px;width:auto;vertical-align:middle;margin-right:6px;opacity:0.9;">' . 'Site Navigator',
        'href'  => false,
        'meta'  => [ 'class' => 'dp-site-nav-root' ],
    ] );

    // --- Bricks Settings ---
    if ( get_option( 'dp_toolbox_site_nav_show_bricks_settings', true ) ) {
        dp_toolbox_site_nav_add_bricks_settings( $wp_admin_bar );
    }

    // --- Templates ---
    dp_toolbox_site_nav_add_templates( $wp_admin_bar );

    // --- Pages ---
    dp_toolbox_site_nav_add_pages( $wp_admin_bar );

    // --- Plugin Settings ---
    if ( get_option( 'dp_toolbox_site_nav_show_plugin_settings', true ) ) {
        dp_toolbox_site_nav_add_plugin_settings( $wp_admin_bar );
    }

}, 999 );

/**
 * Add Bricks settings — as flyout submenu.
 */
function dp_toolbox_site_nav_add_bricks_settings( $wp_admin_bar ) {
    $wp_admin_bar->add_node( [
        'id'     => 'dp-sn-bricks',
        'parent' => 'dp-site-nav',
        'title'  => 'Bricks Instellingen',
        'href'   => esc_url( admin_url( 'admin.php?page=bricks-settings' ) ),
    ] );

    $settings_pages = [
        'general'          => 'Algemeen',
        'builder-access'   => 'Builder Toegang',
        'templates'        => 'Templates',
        'builder'          => 'Builder',
        'performance'      => 'Performance',
        'maintenance-mode' => 'Onderhoudsmodus',
        'api-keys'         => 'API Keys',
        'custom-code'      => 'Custom Code',
    ];

    foreach ( $settings_pages as $slug => $label ) {
        $href = admin_url( 'admin.php?page=bricks-settings#' . $slug );

        // Setting item — has flyout child for new tab
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-bricks-' . $slug,
            'parent' => 'dp-sn-bricks',
            'title'  => $label,
            'href'   => esc_url( $href ),
            'meta'   => [ 'class' => 'dp-sn-parent-mini' ],
        ] );

        // Flyout: open in new tab
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-bricks-' . $slug . '-nt',
            'parent' => 'dp-sn-bricks-' . $slug,
            'title'  => '',
            'href'   => esc_url( $href ),
            'meta'   => [
                'target' => '_blank',
                'class'  => 'dp-sn-mini dp-sn-mini-newtab',
                'title'  => $label . ' in nieuw tabblad',
            ],
        ] );
    }

    if ( class_exists( 'WooCommerce' ) ) {
        $href = admin_url( 'admin.php?page=bricks-settings#woocommerce' );
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-bricks-woo',
            'parent' => 'dp-sn-bricks',
            'title'  => 'WooCommerce',
            'href'   => esc_url( $href ),
            'meta'   => [ 'class' => 'dp-sn-parent-mini' ],
        ] );
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-bricks-woo-nt',
            'parent' => 'dp-sn-bricks-woo',
            'title'  => '',
            'href'   => esc_url( $href ),
            'meta'   => [ 'target' => '_blank', 'class' => 'dp-sn-mini dp-sn-mini-newtab' ],
        ] );
    }
}

/**
 * Add Templates — as flyout submenu with "open in new tab".
 */
function dp_toolbox_site_nav_add_templates( $wp_admin_bar ) {
    global $wpdb;

    $wp_admin_bar->add_node( [
        'id'     => 'dp-sn-templates',
        'parent' => 'dp-site-nav',
        'title'  => 'Templates',
        'href'   => esc_url( admin_url( 'edit.php?post_type=bricks_template' ) ),
        'meta'   => [ 'class' => 'dp-sn-has-border' ],
    ] );

    // Add New
    $wp_admin_bar->add_node( [
        'id'     => 'dp-sn-templates-new',
        'parent' => 'dp-sn-templates',
        'title'  => '+ Nieuw template',
        'href'   => esc_url( admin_url( 'post-new.php?post_type=bricks_template' ) ),
        'meta'   => [ 'class' => 'dp-sn-add-new' ],
    ] );

    $templates = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type = 'bricks_template' AND post_status = 'publish'
         ORDER BY post_title ASC"
    );

    if ( ! $templates ) {
        return;
    }

    foreach ( $templates as $tpl ) {
        $title    = $tpl->post_title ?: '(geen titel)';
        $edit_url = dp_toolbox_site_nav_get_bricks_edit_url( $tpl->ID );

        // Template item — parent of flyout
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-tpl-' . $tpl->ID,
            'parent' => 'dp-sn-templates',
            'title'  => esc_html( $title ),
            'href'   => esc_url( $edit_url ),
            'meta'   => [
                'class' => 'dp-sn-parent-mini',
                'title' => esc_attr( $title ) . ' bewerken in Bricks',
            ],
        ] );

        // Flyout: open in new tab
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-tpl-' . $tpl->ID . '-nt',
            'parent' => 'dp-sn-tpl-' . $tpl->ID,
            'title'  => '',
            'href'   => esc_url( $edit_url ),
            'meta'   => [
                'target' => '_blank',
                'class'  => 'dp-sn-mini dp-sn-mini-newtab',
                'title'  => esc_attr( $title ) . ' in nieuw tabblad',
            ],
        ] );
    }
}

/**
 * Add Pages — as flyout submenu with "open in new tab".
 */
function dp_toolbox_site_nav_add_pages( $wp_admin_bar ) {
    global $wpdb;

    $wp_admin_bar->add_node( [
        'id'     => 'dp-sn-pages',
        'parent' => 'dp-site-nav',
        'title'  => "Pagina's",
        'href'   => esc_url( admin_url( 'edit.php?post_type=page' ) ),
        'meta'   => [ 'class' => 'dp-sn-has-border' ],
    ] );

    $pages = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type = 'page' AND post_status = 'publish'
         ORDER BY post_title ASC"
    );

    if ( ! $pages ) {
        return;
    }

    foreach ( $pages as $page ) {
        $title    = $page->post_title ?: '(geen titel)';
        $edit_url = dp_toolbox_site_nav_get_bricks_edit_url( $page->ID );

        // Page item — parent of flyout
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-page-' . $page->ID,
            'parent' => 'dp-sn-pages',
            'title'  => esc_html( $title ),
            'href'   => esc_url( $edit_url ),
            'meta'   => [
                'class' => 'dp-sn-parent-mini',
                'title' => esc_attr( $title ) . ' bewerken in Bricks',
            ],
        ] );

        // Flyout: open in new tab
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-page-' . $page->ID . '-nt',
            'parent' => 'dp-sn-page-' . $page->ID,
            'title'  => '',
            'href'   => esc_url( $edit_url ),
            'meta'   => [
                'target' => '_blank',
                'class'  => 'dp-sn-mini dp-sn-mini-newtab',
                'title'  => esc_attr( $title ) . ' in nieuw tabblad',
            ],
        ] );
    }
}

/**
 * Add third-party plugin settings — as flyout submenu.
 */
function dp_toolbox_site_nav_add_plugin_settings( $wp_admin_bar ) {
    $plugins = [];

    if ( class_exists( 'Jetonit\AutomaticCSS\Plugin' ) || defined( 'JETONIT_ACSS_VERSION' ) || class_exists( 'Jetonit_ACSS' ) ) {
        $plugins['acss'] = [ 'label' => 'Automatic.css', 'url' => admin_url( 'admin.php?page=jetonit-acss' ) ];
    }
    if ( class_exists( 'Jetonit\AdvancedThemer\Plugin' ) || defined( 'AT_VERSION' ) ) {
        $plugins['at'] = [ 'label' => 'Advanced Themer', 'url' => admin_url( 'admin.php?page=jetonit-at' ) ];
    }
    if ( class_exists( 'BricksExtras\Init' ) || defined( 'JETONIT_BE_VERSION' ) ) {
        $plugins['bricksextras'] = [ 'label' => 'BricksExtras', 'url' => admin_url( 'admin.php?page=jetonit-be' ) ];
    }
    if ( defined( 'JETONIT_BF_VERSION' ) || class_exists( 'Jetonit_Bricksforge' ) ) {
        $plugins['bricksforge'] = [ 'label' => 'Bricksforge', 'url' => admin_url( 'admin.php?page=bricksforge' ) ];
    }
    if ( defined( 'JETONIT_CF_VERSION' ) || class_exists( 'CoreFramework\Plugin' ) ) {
        $plugins['coreframework'] = [ 'label' => 'Core Framework', 'url' => admin_url( 'admin.php?page=jetonit-cf' ) ];
    }
    if ( defined( 'JETONIT_OP_VERSION' ) || class_exists( 'OxyProps\Plugin' ) ) {
        $plugins['oxyprops'] = [ 'label' => 'OxyProps', 'url' => admin_url( 'admin.php?page=jetonit-op' ) ];
    }

    if ( empty( $plugins ) ) {
        return;
    }

    $wp_admin_bar->add_node( [
        'id'     => 'dp-sn-plugins',
        'parent' => 'dp-site-nav',
        'title'  => 'Plugin Instellingen',
        'href'   => false,
        'meta'   => [ 'class' => 'dp-sn-has-border' ],
    ] );

    foreach ( $plugins as $key => $plugin ) {
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-plugin-' . $key,
            'parent' => 'dp-sn-plugins',
            'title'  => $plugin['label'],
            'href'   => esc_url( $plugin['url'] ),
            'meta'   => [ 'class' => 'dp-sn-parent-mini' ],
        ] );
        $wp_admin_bar->add_node( [
            'id'     => 'dp-sn-plugin-' . $key . '-nt',
            'parent' => 'dp-sn-plugin-' . $key,
            'title'  => '',
            'href'   => esc_url( $plugin['url'] ),
            'meta'   => [ 'target' => '_blank', 'class' => 'dp-sn-mini dp-sn-mini-newtab' ],
        ] );
    }
}

/**
 * Get the Bricks editor URL for a given post ID.
 */
function dp_toolbox_site_nav_get_bricks_edit_url( $post_id ) {
    if ( class_exists( 'Bricks\Helpers' ) && method_exists( 'Bricks\Helpers', 'get_builder_edit_link' ) ) {
        return \Bricks\Helpers::get_builder_edit_link( $post_id );
    }

    return add_query_arg( 'bricks', 'run', get_permalink( $post_id ) );
}

/**
 * Show admin bar inside Bricks editor (optional).
 */
add_action( 'wp_head', function () {
    if ( ! get_option( 'dp_toolbox_site_nav_show_admin_bar_in_editor', false ) ) {
        return;
    }

    if ( ! dp_toolbox_site_nav_is_bricks_active() ) {
        return;
    }

    if ( ! function_exists( 'bricks_is_builder_main' ) || ! bricks_is_builder_main() ) {
        return;
    }

    ?>
    <style>
        #wpadminbar { display: block !important; }
        #bricks-builder-iframe,
        #bricks-panel,
        #bricks-preview {
            top: 32px !important;
        }
        #bricks-toolbar {
            top: 32px !important;
        }
        @media screen and (max-width: 782px) {
            #bricks-builder-iframe,
            #bricks-panel,
            #bricks-preview,
            #bricks-toolbar {
                top: 46px !important;
            }
        }
    </style>
    <?php
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
