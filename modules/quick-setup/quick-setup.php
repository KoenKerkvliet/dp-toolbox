<?php
/**
 * Module Name: Quick Setup
 * Description: Configureer een nieuwe WordPress-installatie met één klik — taal, tijdzone, datum en meer.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Default settings profile                                           */
/* ------------------------------------------------------------------ */

function dp_toolbox_qs_get_defaults() {
    return [
        // Taal
        'WPLANG'               => [ 'value' => 'nl_NL',         'label' => 'Taal',                  'group' => 'general',   'display' => 'Nederlands' ],

        // Tagline leegmaken
        'blogdescription'      => [ 'value' => '',               'label' => 'Tagline',               'group' => 'general',   'display' => 'Leegmaken' ],

        // Tijdzone & datum/tijd
        'timezone_string'      => [ 'value' => 'Europe/Amsterdam', 'label' => 'Tijdzone',            'group' => 'datetime',  'display' => 'Europe/Amsterdam' ],
        'date_format'          => [ 'value' => 'j F Y',          'label' => 'Datumweergave',          'group' => 'datetime',  'display' => '16 april 2026' ],
        'time_format'          => [ 'value' => 'H:i',            'label' => 'Tijdweergave',           'group' => 'datetime',  'display' => '17:43 (24-uurs)' ],
        'start_of_week'        => [ 'value' => '1',              'label' => 'Eerste dag van de week',  'group' => 'datetime',  'display' => 'Maandag' ],

        // Zoekmachines
        'blog_public'          => [ 'value' => '0',              'label' => 'Zoekmachine-indexering', 'group' => 'seo',       'display' => 'Uitgeschakeld (noindex)' ],

        // Permalinks
        'permalink_structure'  => [ 'value' => '/%postname%/',   'label' => 'Permalink-structuur',    'group' => 'general',   'display' => '/bericht-naam/' ],

        // Discussie — spam-preventie
        'default_comment_status' => [ 'value' => 'closed',      'label' => 'Reacties standaard',     'group' => 'content',   'display' => 'Uitgeschakeld' ],
        'default_ping_status'    => [ 'value' => 'closed',      'label' => 'Pingbacks standaard',    'group' => 'content',   'display' => 'Uitgeschakeld' ],

        // Gravatar uitschakelen
        'show_avatars'           => [ 'value' => '0',            'label' => 'Gravatars',              'group' => 'content',   'display' => 'Uitgeschakeld (privacy + snelheid)' ],

        // Media uploads — jaar/maand mappen
        'uploads_use_yearmonth_folders' => [ 'value' => '0',    'label' => 'Uploads in jaar/maand-mappen', 'group' => 'content', 'display' => 'Uitgeschakeld (alles in uploads/)' ],

        // Thumbnails resetten (voor Bricks / page builder sites)
        'thumbnail_size_w'     => [ 'value' => '0',   'label' => 'Thumbnail breedte',          'group' => 'thumbnails', 'display' => '0 (uit)' ],
        'thumbnail_size_h'     => [ 'value' => '0',   'label' => 'Thumbnail hoogte',           'group' => 'thumbnails', 'display' => '0 (uit)' ],
        'medium_size_w'        => [ 'value' => '0',   'label' => 'Medium breedte',             'group' => 'thumbnails', 'display' => '0 (uit)' ],
        'medium_size_h'        => [ 'value' => '0',   'label' => 'Medium hoogte',              'group' => 'thumbnails', 'display' => '0 (uit)' ],
        'large_size_w'         => [ 'value' => '0',   'label' => 'Large breedte',              'group' => 'thumbnails', 'display' => '0 (uit)' ],
        'large_size_h'         => [ 'value' => '0',   'label' => 'Large hoogte',               'group' => 'thumbnails', 'display' => '0 (uit)' ],

        // Homepage
        'show_on_front'        => [ 'value' => 'page',           'label' => 'Voorpagina toont',       'group' => 'homepage',  'display' => 'Statische pagina' ],
    ];
}

/* ------------------------------------------------------------------ */
/*  Hide default dashboard metaboxes when enabled                      */
/* ------------------------------------------------------------------ */

add_action( 'wp_dashboard_setup', function () {
    if ( ! get_option( 'dp_toolbox_qs_empty_metaboxes' ) ) {
        return;
    }
    global $wp_meta_boxes;
    if ( ! isset( $wp_meta_boxes['dashboard'] ) ) {
        return;
    }

    // Wis alle metaboxen behalve DP Toolbox eigen widgets (prefix dp_toolbox_)
    foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
        if ( ! is_array( $priorities ) ) {
            continue;
        }
        foreach ( $priorities as $priority => $widgets ) {
            if ( ! is_array( $widgets ) ) {
                continue;
            }
            foreach ( $widgets as $id => $widget ) {
                if ( strpos( (string) $id, 'dp_toolbox_' ) !== 0 ) {
                    unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $id ] );
                }
            }
        }
    }
}, 9999 );

/* ------------------------------------------------------------------ */
/*  Block TasteWP welcome banners when dismissed                       */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
    if ( ! get_option( 'dp_toolbox_hide_tastewp_banners' ) ) {
        return;
    }

    // Remove the TasteWP intro banner actions if they exist
    remove_all_actions( 'tastewp_banners_intro' );
    remove_all_actions( 'tastewp_banners_intro_small' );

    // Also hide via CSS as a safety net
    add_action( 'admin_head', function () {
        echo '<style>#TSW_CONTAINER, .tastewp-banner, [id^="TSW_"], #TSW_HOLDER { display: none !important; }</style>';
    } );
}, 999 );

/* ------------------------------------------------------------------ */
/*  Starter content (pages & posts)                                    */
/* ------------------------------------------------------------------ */

function dp_toolbox_qs_get_starter_content() {
    $lorem1 = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.';

    $lorem2 = 'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum. Curabitur pretium tincidunt lacus. Nulla gravida orci a odio. Nullam varius, turpis et commodo pharetra, est eros bibendum elit, nec luctus magna felis sollicitudin mauris.';

    $lorem3 = 'Praesent dapibus, neque id cursus faucibus, tortor neque egestas augue, eu vulputate magna eros eu erat. Aliquam erat volutpat. Nam dui mi, tincidunt quis, accumsan porttitor, facilisis luctus, metus. Phasellus ultrices nulla quis nibh. Quisque a lectus.';

    return [
        'page_over_ons' => [
            'title'     => 'Over ons',
            'post_type' => 'page',
            'content'   => '',
            'label'     => 'Pagina: Over ons',
        ],
        'page_contact' => [
            'title'     => 'Contact',
            'post_type' => 'page',
            'content'   => '',
            'label'     => 'Pagina: Contact',
        ],
        'post_nieuws_1' => [
            'title'     => 'Nieuwsbericht 1',
            'post_type' => 'post',
            'content'   => "<p>{$lorem1}</p>\n\n<p>{$lorem2}</p>\n\n<p>{$lorem3}</p>",
            'label'     => 'Bericht: Nieuwsbericht 1',
        ],
        'post_nieuws_2' => [
            'title'     => 'Nieuwsbericht 2',
            'post_type' => 'post',
            'content'   => "<p>{$lorem2}</p>\n\n<p>{$lorem3}</p>\n\n<p>{$lorem1}</p>",
            'label'     => 'Bericht: Nieuwsbericht 2',
        ],
        'post_nieuws_3' => [
            'title'     => 'Nieuwsbericht 3',
            'post_type' => 'post',
            'content'   => "<p>{$lorem3}</p>\n\n<p>{$lorem1}</p>\n\n<p>{$lorem2}</p>",
            'label'     => 'Bericht: Nieuwsbericht 3',
        ],
    ];
}

/* ------------------------------------------------------------------ */
/*  AJAX: apply settings                                               */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_qs_apply', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }
    check_ajax_referer( 'dp_toolbox_quick_setup', 'nonce' );

    // Prevent timeout on slow operations (plugin/theme deletes)
    @set_time_limit( 300 );
    @ini_set( 'memory_limit', '256M' );

    $selected      = $_POST['settings'] ?? [];
    $content_items = $_POST['content'] ?? [];
    $cleanup_items = $_POST['cleanup'] ?? [];
    if ( ! is_array( $selected ) ) $selected = [];
    if ( ! is_array( $content_items ) ) $content_items = [];
    if ( ! is_array( $cleanup_items ) ) $cleanup_items = [];

    if ( empty( $selected ) && empty( $content_items ) && empty( $cleanup_items ) ) {
        wp_send_json_error( 'Geen items geselecteerd.' );
    }

    // Start output buffer — catch any stray output from WP_Filesystem / delete_plugins
    ob_start();

    $defaults = dp_toolbox_qs_get_defaults();
    $applied  = [];
    $errors   = [];

    foreach ( $selected as $key ) {
        $key = sanitize_text_field( $key );
        if ( ! isset( $defaults[ $key ] ) ) {
            continue;
        }

        $setting = $defaults[ $key ];

        // Special handling for language
        if ( $key === 'WPLANG' ) {
            // Download language pack if not installed
            if ( ! in_array( $setting['value'], get_available_languages() ) ) {
                require_once ABSPATH . 'wp-admin/includes/translation-install.php';
                $result = wp_download_language_pack( $setting['value'] );
                if ( ! $result ) {
                    $errors[] = 'Taalpakket kon niet gedownload worden.';
                    continue;
                }
            }
        }

        // Special handling for homepage
        if ( $key === 'show_on_front' ) {
            // Create Home page if it doesn't exist
            $home_page = get_page_by_title( 'Home' );
            if ( ! $home_page ) {
                $home_page_id = wp_insert_post( [
                    'post_title'  => 'Home',
                    'post_status' => 'publish',
                    'post_type'   => 'page',
                ] );
            } else {
                $home_page_id = $home_page->ID;
            }

            if ( $home_page_id && ! is_wp_error( $home_page_id ) ) {
                update_option( 'page_on_front', $home_page_id );
            }
        }

        // Special handling for permalinks
        if ( $key === 'permalink_structure' ) {
            update_option( $key, $setting['value'] );
            flush_rewrite_rules();
            $applied[] = $setting['label'];
            continue;
        }

        update_option( $key, $setting['value'] );
        $applied[] = $setting['label'];
    }

    // Handle cleanup items
    if ( ! empty( $cleanup_items ) ) {
        foreach ( $cleanup_items as $cleanup_key ) {
            $cleanup_key = sanitize_text_field( $cleanup_key );

            if ( $cleanup_key === 'hello_world' ) {
                // Delete "Hello world!" post (ID 1 or by title)
                $hello = get_page_by_title( 'Hello world!', OBJECT, 'post' );
                if ( ! $hello ) $hello = get_post( 1 );
                if ( $hello && $hello->post_type === 'post' && strpos( strtolower( $hello->post_title ), 'hello' ) !== false ) {
                    wp_delete_post( $hello->ID, true );
                    $applied[] = 'Hello World bericht verwijderd';
                }
            }

            if ( $cleanup_key === 'sample_page' ) {
                // Delete "Sample Page" / "Voorbeeldpagina"
                $sample = get_page_by_title( 'Sample Page', OBJECT, 'page' );
                if ( ! $sample ) $sample = get_page_by_title( 'Voorbeeldpagina', OBJECT, 'page' );
                if ( $sample ) {
                    wp_delete_post( $sample->ID, true );
                    $applied[] = 'Voorbeeldpagina verwijderd';
                }
            }

            if ( $cleanup_key === 'tastewp_notices' ) {
                // Dismiss TasteWP admin notices + welcome banner
                update_option( 'hide_tastewp_notice', 1 );
                update_option( 'hide_tastewp_notice_small', 1 );
                update_option( 'woocommerce_task_list_welcome_modal_dismissed', 'yes' );
                update_option( 'dp_toolbox_hide_tastewp_banners', 1 );
                $applied[] = 'TasteWP-banners en notices verborgen';
            }

            if ( $cleanup_key === 'empty_metaboxes' ) {
                // Hide all default dashboard metaboxes
                update_option( 'dp_toolbox_qs_empty_metaboxes', 1 );
                $applied[] = 'Dashboard-metaboxen leeggemaakt';
            }

            if ( $cleanup_key === 'welcome_panel' ) {
                // Hide the "Welkom bij WordPress!" panel on dashboard
                // Per-user: set the show_welcome_panel user meta to 0 for all admins
                $user_query = new WP_User_Query( [ 'role__in' => [ 'administrator' ] ] );
                foreach ( $user_query->get_results() as $u ) {
                    update_user_meta( $u->ID, 'show_welcome_panel', 0 );
                }
                $applied[] = 'WordPress welkomscherm verborgen';
            }

            if ( $cleanup_key === 'empty_trash' ) {
                $trashed = get_posts( [
                    'post_status'    => 'trash',
                    'post_type'      => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ] );
                $count = 0;
                foreach ( $trashed as $post_id ) {
                    wp_delete_post( $post_id, true );
                    $count++;
                }
                if ( $count > 0 ) {
                    $applied[] = $count . ' item(s) uit prullenbak verwijderd';
                }
            }

            if ( $cleanup_key === 'inactive_themes' ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/theme.php';

                // Initialize WP_Filesystem (required for delete_theme)
                if ( ! WP_Filesystem() ) {
                    $errors[] = 'Filesystem niet toegankelijk voor thema-verwijdering.';
                    continue;
                }

                $active_theme  = get_stylesheet();
                $parent_theme  = get_template();
                $all_themes    = wp_get_themes();
                $deleted_count = 0;

                foreach ( $all_themes as $slug => $theme ) {
                    // Keep active theme and its parent
                    if ( $slug === $active_theme || $slug === $parent_theme ) {
                        continue;
                    }
                    $result = delete_theme( $slug );
                    if ( ! is_wp_error( $result ) ) {
                        $deleted_count++;
                    }
                }

                if ( $deleted_count > 0 ) {
                    $applied[] = $deleted_count . ' inactief thema\'s verwijderd';
                }
            }
        }
    }

    // Handle starter content (pages & posts)
    if ( ! empty( $content_items ) ) {
        $starter = dp_toolbox_qs_get_starter_content();
        foreach ( $content_items as $item_key ) {
            $item_key = sanitize_text_field( $item_key );
            if ( ! isset( $starter[ $item_key ] ) ) {
                continue;
            }
            $item = $starter[ $item_key ];

            // Check if already exists
            $existing = get_page_by_title( $item['title'], OBJECT, $item['post_type'] );
            if ( $existing ) {
                $applied[] = $item['title'] . ' (bestond al)';
                continue;
            }

            $post_id = wp_insert_post( [
                'post_title'   => $item['title'],
                'post_content' => $item['content'],
                'post_status'  => 'publish',
                'post_type'    => $item['post_type'],
            ] );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                $applied[] = $item['title'];
            } else {
                $errors[] = $item['title'] . ' kon niet aangemaakt worden.';
            }
        }
    }

    // Discard any stray output from filesystem operations
    @ob_end_clean();

    wp_send_json_success( [
        'applied' => $applied,
        'errors'  => $errors,
        'message' => count( $applied ) . ' item(s) toegepast.' . ( ! empty( $errors ) ? ' ' . count( $errors ) . ' fout(en).' : '' ),
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
