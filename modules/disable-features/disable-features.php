<?php
/**
 * Module Name: Disable Features
 * Description: Schakel onnodige WordPress-functies uit via een overzichtelijk paneel.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * All available features grouped by category.
 */
function dp_toolbox_df_get_features() {
    return [
        'admin' => [
            'label' => 'Admin opruimen',
            'icon'  => 'dashicons-admin-generic',
            'items' => [
                'howdy_message'       => [
                    'label' => 'Howdy-bericht',
                    'desc'  => 'Verwijdert het "Howdy, Naam" bericht rechtsboven in de admin bar.',
                ],
                'wp_logo_admin_bar'   => [
                    'label' => 'WordPress logo in admin bar',
                    'desc'  => 'Verbergt het WordPress-logo linksboven in de admin bar.',
                ],
                'welcome_panel'       => [
                    'label' => 'Welcome panel',
                    'desc'  => 'Verbergt het welkomstpaneel op het dashboard.',
                ],
                'admin_footer_text'   => [
                    'label' => 'Admin footer tekst',
                    'desc'  => 'Verwijdert de "Bedankt voor het gebruik van WordPress" tekst onderaan.',
                ],
                'new_content_menu'    => [
                    'label' => '+ Nieuw menu',
                    'desc'  => 'Verbergt het "+ Nieuw" menu in de admin bar.',
                ],
            ],
        ],
        'frontend' => [
            'label' => 'Frontend opruimen',
            'icon'  => 'dashicons-admin-appearance',
            'items' => [
                'emoji_scripts'       => [
                    'label' => 'Emoji scripts',
                    'desc'  => 'Verwijdert de standaard WordPress emoji scripts en styles.',
                ],
                'wp_version'          => [
                    'label' => 'WordPress versienummer',
                    'desc'  => 'Verwijdert het WP-versienummer uit de broncode.',
                ],
                'rss_feeds'           => [
                    'label' => 'RSS feeds',
                    'desc'  => 'Schakelt alle RSS- en Atom-feeds uit.',
                ],
                'self_pingbacks'      => [
                    'label' => 'Self-pingbacks',
                    'desc'  => 'Voorkomt dat WordPress pingbacks naar je eigen site stuurt.',
                ],
                'frontend_admin_bar'  => [
                    'label' => 'Admin bar op frontend',
                    'desc'  => 'Verbergt de admin bar op de frontend voor alle gebruikers.',
                ],
            ],
        ],
        'security' => [
            'label' => 'Beveiliging',
            'icon'  => 'dashicons-shield',
            'items' => [
                'xmlrpc'              => [
                    'label' => 'XML-RPC',
                    'desc'  => 'Schakelt XML-RPC volledig uit (veelgebruikt aanvalspunt).',
                ],
                'rest_api_public'     => [
                    'label' => 'REST API voor bezoekers',
                    'desc'  => 'Beperkt de REST API tot ingelogde gebruikers.',
                ],
                'application_passwords' => [
                    'label' => 'Application Passwords',
                    'desc'  => 'Schakelt de Application Passwords functie uit.',
                ],
                'author_archives'     => [
                    'label' => 'Auteur-archieven',
                    'desc'  => 'Schakelt auteur-archieven uit (voorkomt username enumeration).',
                ],
            ],
        ],
        'functionality' => [
            'label' => 'Functionaliteit',
            'icon'  => 'dashicons-admin-settings',
            'items' => [
                'comments'            => [
                    'label' => 'Reacties',
                    'desc'  => 'Schakelt reacties volledig uit op de hele site.',
                ],
            ],
        ],
    ];
}

/**
 * Get saved disabled features.
 */
function dp_toolbox_df_get_disabled() {
    return (array) get_option( 'dp_toolbox_disabled_features', [] );
}

function dp_toolbox_df_is_disabled( $key ) {
    return in_array( $key, dp_toolbox_df_get_disabled(), true );
}

/* ------------------------------------------------------------------ */
/*  Apply all disabled features                                        */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
    /* --- Admin --- */
    if ( dp_toolbox_df_is_disabled( 'howdy_message' ) ) {
        add_filter( 'admin_bar_menu', function ( $wp_admin_bar ) {
            $account = $wp_admin_bar->get_node( 'my-account' );
            if ( $account ) {
                $current_user = wp_get_current_user();
                $account->title = str_replace(
                    [ 'Howdy,', 'Hallo,' ],
                    '',
                    $account->title
                );
                $wp_admin_bar->add_node( $account );
            }
        }, 9999 );
    }

    if ( dp_toolbox_df_is_disabled( 'wp_logo_admin_bar' ) ) {
        add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
            $wp_admin_bar->remove_node( 'wp-logo' );
        }, 999 );
    }

    if ( dp_toolbox_df_is_disabled( 'new_content_menu' ) ) {
        add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
            $wp_admin_bar->remove_node( 'new-content' );
        }, 999 );
    }

    if ( dp_toolbox_df_is_disabled( 'welcome_panel' ) ) {
        remove_action( 'welcome_panel', 'wp_welcome_panel' );
    }

    if ( dp_toolbox_df_is_disabled( 'admin_footer_text' ) ) {
        add_filter( 'admin_footer_text', '__return_empty_string', 999 );
        add_filter( 'update_footer', '__return_empty_string', 999 );
    }

    /* --- Frontend --- */
    if ( dp_toolbox_df_is_disabled( 'emoji_scripts' ) ) {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        add_filter( 'emoji_svg_url', '__return_false' );
    }

    if ( dp_toolbox_df_is_disabled( 'wp_version' ) ) {
        remove_action( 'wp_head', 'wp_generator' );
        add_filter( 'the_generator', '__return_empty_string' );
    }

    if ( dp_toolbox_df_is_disabled( 'rss_feeds' ) ) {
        remove_action( 'wp_head', 'feed_links', 2 );
        remove_action( 'wp_head', 'feed_links_extra', 3 );
        add_action( 'do_feed', 'dp_toolbox_df_disable_feed', 1 );
        add_action( 'do_feed_rdf', 'dp_toolbox_df_disable_feed', 1 );
        add_action( 'do_feed_rss', 'dp_toolbox_df_disable_feed', 1 );
        add_action( 'do_feed_rss2', 'dp_toolbox_df_disable_feed', 1 );
        add_action( 'do_feed_atom', 'dp_toolbox_df_disable_feed', 1 );
    }

    if ( dp_toolbox_df_is_disabled( 'self_pingbacks' ) ) {
        add_action( 'pre_ping', function ( &$links ) {
            $home = home_url();
            foreach ( $links as $i => $link ) {
                if ( strpos( $link, $home ) === 0 ) {
                    unset( $links[ $i ] );
                }
            }
        } );
    }

    if ( dp_toolbox_df_is_disabled( 'frontend_admin_bar' ) ) {
        add_filter( 'show_admin_bar', '__return_false' );
    }

    /* --- Security --- */
    if ( dp_toolbox_df_is_disabled( 'xmlrpc' ) ) {
        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'wp_headers', function ( $headers ) {
            unset( $headers['X-Pingback'] );
            return $headers;
        } );
    }

    if ( dp_toolbox_df_is_disabled( 'rest_api_public' ) ) {
        add_filter( 'rest_authentication_errors', function ( $result ) {
            if ( true === $result || is_wp_error( $result ) ) {
                return $result;
            }
            if ( ! is_user_logged_in() ) {
                return new WP_Error( 'rest_not_logged_in', 'Authentication required.', [ 'status' => 401 ] );
            }
            return $result;
        } );
    }

    if ( dp_toolbox_df_is_disabled( 'application_passwords' ) ) {
        add_filter( 'wp_is_application_passwords_available', '__return_false' );
    }

    if ( dp_toolbox_df_is_disabled( 'author_archives' ) ) {
        add_action( 'template_redirect', function () {
            if ( is_author() ) {
                wp_redirect( home_url(), 301 );
                exit;
            }
        } );
    }

    /* --- Functionality --- */
    if ( dp_toolbox_df_is_disabled( 'comments' ) ) {
        add_filter( 'comments_open', '__return_false', 20, 2 );
        add_filter( 'pings_open', '__return_false', 20, 2 );
        add_filter( 'comments_array', '__return_empty_array', 10, 2 );
        add_action( 'admin_menu', function () {
            remove_menu_page( 'edit-comments.php' );
        } );
        add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
            $wp_admin_bar->remove_node( 'comments' );
        }, 999 );
        add_action( 'admin_init', function () {
            remove_meta_box( 'commentsdiv', null, 'normal' );
            remove_meta_box( 'commentstatusdiv', null, 'normal' );
        } );
    }
}, 1 );

function dp_toolbox_df_disable_feed() {
    wp_redirect( home_url(), 301 );
    exit;
}

/* ------------------------------------------------------------------ */
/*  Admin bar: detect plugin nodes & hide disabled ones                */
/* ------------------------------------------------------------------ */

/** Core node IDs that ship with WordPress. */
function dp_toolbox_df_core_admin_bar_ids() {
    return [
        'wp-logo', 'about', 'wporg', 'documentation', 'support-forums', 'feedback',
        'site-name', 'view-site', 'dashboard', 'appearance', 'themes', 'widgets',
        'menus', 'customize', 'updates', 'comments', 'new-content', 'new-post',
        'new-media', 'new-page', 'new-user', 'edit', 'my-account', 'user-actions',
        'user-info', 'edit-profile', 'logout', 'search', 'top-secondary',
        'menu-toggle',
    ];
}

/** Capture plugin-added admin bar items and store in transient. */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    $core  = dp_toolbox_df_core_admin_bar_ids();
    $nodes = $wp_admin_bar->get_nodes();
    $plugin_items = [];

    foreach ( $nodes as $id => $node ) {
        if ( ! empty( $node->parent ) && $node->parent !== 'root' && $node->parent !== 'top-secondary' ) {
            continue;
        }
        if ( in_array( $id, $core, true ) ) {
            continue;
        }
        $plugin_items[ $id ] = wp_strip_all_tags( $node->title );
    }

    if ( ! empty( $plugin_items ) ) {
        set_transient( 'dp_toolbox_admin_bar_plugins', $plugin_items, DAY_IN_SECONDS );
    }
}, PHP_INT_MAX - 1 );

/** Remove admin bar items the user has disabled. */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    $hidden = (array) get_option( 'dp_toolbox_hidden_admin_bar', [] );
    foreach ( $hidden as $node_id ) {
        $wp_admin_bar->remove_node( $node_id );
    }
}, PHP_INT_MAX );

/** Get detected plugin admin bar items. */
function dp_toolbox_df_get_admin_bar_plugins() {
    return (array) get_transient( 'dp_toolbox_admin_bar_plugins' );
}

/** Get hidden admin bar items. */
function dp_toolbox_df_get_hidden_admin_bar() {
    return (array) get_option( 'dp_toolbox_hidden_admin_bar', [] );
}

/* Admin page */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}