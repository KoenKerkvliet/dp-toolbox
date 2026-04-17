<?php
/**
 * Module Name: Activity Log
 * Description: Houd bij wie wat doet op de site — logins, content, gebruikers, plugins en instellingen.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Create database table on first load                                */
/* ------------------------------------------------------------------ */

function dp_toolbox_al_ensure_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'dp_activity_log';

    if ( get_option( 'dp_toolbox_al_table_version' ) === '1.0' ) {
        return;
    }

    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_type varchar(50) NOT NULL,
        event_action varchar(100) NOT NULL,
        object_type varchar(50) DEFAULT '',
        object_id bigint(20) unsigned DEFAULT 0,
        object_name varchar(255) DEFAULT '',
        user_id bigint(20) unsigned DEFAULT 0,
        username varchar(60) DEFAULT '',
        user_role varchar(50) DEFAULT '',
        user_ip varchar(45) DEFAULT '',
        details text DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_type (event_type),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'dp_toolbox_al_table_version', '1.0' );
}

add_action( 'admin_init', 'dp_toolbox_al_ensure_table' );

/* ------------------------------------------------------------------ */
/*  Core logging function                                              */
/* ------------------------------------------------------------------ */

function dp_toolbox_al_log( $event_type, $action, $args = [] ) {
    global $wpdb;

    $user = wp_get_current_user();

    $wpdb->insert(
        $wpdb->prefix . 'dp_activity_log',
        [
            'event_type'  => sanitize_key( $event_type ),
            'event_action'=> sanitize_text_field( $action ),
            'object_type' => sanitize_key( $args['object_type'] ?? '' ),
            'object_id'   => absint( $args['object_id'] ?? 0 ),
            'object_name' => sanitize_text_field( $args['object_name'] ?? '' ),
            'user_id'     => $user->ID ?? 0,
            'username'    => $user->user_login ?? '',
            'user_role'   => ! empty( $user->roles ) ? implode( ', ', $user->roles ) : '',
            'user_ip'     => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'details'     => sanitize_text_field( $args['details'] ?? '' ),
            'created_at'  => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
    );
}

/* ================================================================== */
/*  SENSORS                                                            */
/* ================================================================== */

/* ------------------------------------------------------------------ */
/*  1. Login / Logout sensor                                           */
/* ------------------------------------------------------------------ */

add_action( 'wp_login', function ( $username, $user ) {
    dp_toolbox_al_log( 'auth', 'Ingelogd', [
        'object_type' => 'user',
        'object_id'   => $user->ID,
        'object_name' => $user->display_name,
        'details'     => 'Rol: ' . implode( ', ', $user->roles ),
    ] );
}, 10, 2 );

add_action( 'wp_logout', function ( $user_id ) {
    $user = get_userdata( $user_id );
    dp_toolbox_al_log( 'auth', 'Uitgelogd', [
        'object_type' => 'user',
        'object_id'   => $user_id,
        'object_name' => $user ? $user->display_name : '',
    ] );
} );

add_action( 'wp_login_failed', function ( $username ) {
    dp_toolbox_al_log( 'auth', 'Mislukte login', [
        'object_type' => 'user',
        'object_name' => $username,
        'details'     => 'Onbekende gebruiker of fout wachtwoord',
    ] );
} );

/* ------------------------------------------------------------------ */
/*  2. Post / Page sensor                                              */
/* ------------------------------------------------------------------ */

add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
    // Skip revisions, auto-drafts, nav_menu_item
    if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) return;
    if ( in_array( $post->post_type, [ 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'wp_global_styles' ], true ) ) return;
    if ( $old_status === 'new' && $new_status === 'auto-draft' ) return;

    $type_label = get_post_type_object( $post->post_type );
    $type_name  = $type_label ? $type_label->labels->singular_name : $post->post_type;

    if ( $old_status === 'auto-draft' && $new_status !== 'auto-draft' ) {
        $action = $type_name . ' aangemaakt';
    } elseif ( $new_status === 'trash' ) {
        $action = $type_name . ' naar prullenbak verplaatst';
    } elseif ( $old_status === 'trash' ) {
        $action = $type_name . ' hersteld uit prullenbak';
    } elseif ( $new_status === 'publish' && $old_status !== 'publish' ) {
        $action = $type_name . ' gepubliceerd';
    } elseif ( $old_status === $new_status ) {
        $action = $type_name . ' bijgewerkt';
    } else {
        $action = $type_name . ' status: ' . $old_status . ' → ' . $new_status;
    }

    dp_toolbox_al_log( 'content', $action, [
        'object_type' => $post->post_type,
        'object_id'   => $post->ID,
        'object_name' => $post->post_title,
    ] );
}, 10, 3 );

add_action( 'before_delete_post', function ( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post || wp_is_post_revision( $post ) ) return;
    if ( in_array( $post->post_type, [ 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'wp_global_styles' ], true ) ) return;

    $type_label = get_post_type_object( $post->post_type );
    $type_name  = $type_label ? $type_label->labels->singular_name : $post->post_type;

    dp_toolbox_al_log( 'content', $type_name . ' permanent verwijderd', [
        'object_type' => $post->post_type,
        'object_id'   => $post->ID,
        'object_name' => $post->post_title,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  3. User sensor                                                     */
/* ------------------------------------------------------------------ */

add_action( 'user_register', function ( $user_id ) {
    $user = get_userdata( $user_id );
    dp_toolbox_al_log( 'user', 'Gebruiker aangemaakt', [
        'object_type' => 'user',
        'object_id'   => $user_id,
        'object_name' => $user ? $user->display_name : '',
        'details'     => 'Rol: ' . ( $user ? implode( ', ', $user->roles ) : '' ),
    ] );
} );

add_action( 'delete_user', function ( $user_id ) {
    $user = get_userdata( $user_id );
    dp_toolbox_al_log( 'user', 'Gebruiker verwijderd', [
        'object_type' => 'user',
        'object_id'   => $user_id,
        'object_name' => $user ? $user->display_name : '',
    ] );
} );

add_action( 'set_user_role', function ( $user_id, $role, $old_roles ) {
    $user = get_userdata( $user_id );
    dp_toolbox_al_log( 'user', 'Gebruikersrol gewijzigd', [
        'object_type' => 'user',
        'object_id'   => $user_id,
        'object_name' => $user ? $user->display_name : '',
        'details'     => implode( ', ', $old_roles ) . ' → ' . $role,
    ] );
}, 10, 3 );

add_action( 'profile_update', function ( $user_id, $old_data ) {
    $user = get_userdata( $user_id );
    $changes = [];
    if ( $user->user_email !== $old_data->user_email ) $changes[] = 'e-mail';
    if ( $user->display_name !== $old_data->display_name ) $changes[] = 'weergavenaam';
    if ( empty( $changes ) ) return;

    dp_toolbox_al_log( 'user', 'Profiel bijgewerkt', [
        'object_type' => 'user',
        'object_id'   => $user_id,
        'object_name' => $user->display_name,
        'details'     => 'Gewijzigd: ' . implode( ', ', $changes ),
    ] );
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  4. Plugin sensor                                                   */
/* ------------------------------------------------------------------ */

add_action( 'activated_plugin', function ( $plugin ) {
    $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
    dp_toolbox_al_log( 'plugin', 'Plugin geactiveerd', [
        'object_type' => 'plugin',
        'object_name' => $data['Name'] ?: $plugin,
        'details'     => $plugin,
    ] );
} );

add_action( 'deactivated_plugin', function ( $plugin ) {
    $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
    dp_toolbox_al_log( 'plugin', 'Plugin gedeactiveerd', [
        'object_type' => 'plugin',
        'object_name' => $data['Name'] ?: $plugin,
        'details'     => $plugin,
    ] );
} );

add_action( 'upgrader_process_complete', function ( $upgrader, $data ) {
    if ( $data['type'] === 'plugin' && $data['action'] === 'update' ) {
        $plugins = $data['plugins'] ?? [];
        foreach ( $plugins as $plugin ) {
            $info = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
            dp_toolbox_al_log( 'plugin', 'Plugin bijgewerkt', [
                'object_type' => 'plugin',
                'object_name' => $info['Name'] ?: $plugin,
                'details'     => 'Naar versie ' . ( $info['Version'] ?? '?' ),
            ] );
        }
    }
    if ( $data['type'] === 'theme' && $data['action'] === 'update' ) {
        $themes = $data['themes'] ?? [];
        foreach ( $themes as $theme_slug ) {
            $theme = wp_get_theme( $theme_slug );
            dp_toolbox_al_log( 'plugin', 'Thema bijgewerkt', [
                'object_type' => 'theme',
                'object_name' => $theme->get( 'Name' ) ?: $theme_slug,
                'details'     => 'Naar versie ' . ( $theme->get( 'Version' ) ?? '?' ),
            ] );
        }
    }
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  5. Settings sensor (core options)                                  */
/* ------------------------------------------------------------------ */

function dp_toolbox_al_watch_option( $option, $old, $new ) {
    $labels = [
        'blogname'             => 'Sitenaam',
        'blogdescription'      => 'Tagline',
        'siteurl'              => 'Site-URL',
        'home'                 => 'Home-URL',
        'admin_email'          => 'Admin e-mail',
        'blog_public'          => 'Zoekmachine-indexering',
        'permalink_structure'  => 'Permalink-structuur',
        'timezone_string'      => 'Tijdzone',
        'date_format'          => 'Datumweergave',
        'time_format'          => 'Tijdweergave',
        'WPLANG'               => 'Taal',
        'default_role'         => 'Standaard gebruikersrol',
        'users_can_register'   => 'Registratie openstellen',
    ];

    if ( ! isset( $labels[ $option ] ) ) return;
    if ( $old === $new ) return;

    dp_toolbox_al_log( 'settings', $labels[ $option ] . ' gewijzigd', [
        'object_type' => 'option',
        'object_name' => $option,
        'details'     => '"' . $old . '" → "' . $new . '"',
    ] );
}

$watched_options = [
    'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
    'blog_public', 'permalink_structure', 'timezone_string',
    'date_format', 'time_format', 'WPLANG', 'default_role', 'users_can_register',
];
foreach ( $watched_options as $opt ) {
    add_action( "update_option_{$opt}", function ( $old, $new ) use ( $opt ) {
        dp_toolbox_al_watch_option( $opt, $old, $new );
    }, 10, 2 );
}

/* ------------------------------------------------------------------ */
/*  AJAX: get log entries                                              */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_al_get_log', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_activity_log', 'nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'dp_activity_log';

    $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
    $per_page = 30;
    $offset   = ( $page - 1 ) * $per_page;
    $search   = sanitize_text_field( $_POST['search'] ?? '' );
    $type     = sanitize_key( $_POST['type'] ?? '' );

    $where = '1=1';
    $params = [];

    if ( $search ) {
        $where .= ' AND (object_name LIKE %s OR username LIKE %s OR event_action LIKE %s OR details LIKE %s)';
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $params = array_merge( $params, [ $like, $like, $like, $like ] );
    }

    if ( $type ) {
        $where .= ' AND event_type = %s';
        $params[] = $type;
    }

    $total = $wpdb->get_var(
        $params
            ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params )
            : "SELECT COUNT(*) FROM {$table} WHERE {$where}"
    );

    $params_with_limit = array_merge( $params, [ $per_page, $offset ] );
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $params_with_limit
        )
    );

    wp_send_json_success( [
        'rows'       => $rows,
        'total'      => (int) $total,
        'page'       => $page,
        'total_pages'=> ceil( $total / $per_page ),
    ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: clear log                                                    */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_al_clear', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_activity_log', 'nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'dp_activity_log';
    $wpdb->query( "TRUNCATE TABLE {$table}" );

    wp_send_json_success( [ 'message' => 'Log gewist.' ] );
} );

/* ------------------------------------------------------------------ */
/*  Auto-cleanup: remove entries older than 90 days                    */
/* ------------------------------------------------------------------ */

add_action( 'dp_toolbox_al_cleanup', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'dp_activity_log';
    $wpdb->query( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
} );

if ( ! wp_next_scheduled( 'dp_toolbox_al_cleanup' ) ) {
    wp_schedule_event( time(), 'daily', 'dp_toolbox_al_cleanup' );
}

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
