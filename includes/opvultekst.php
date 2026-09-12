<?php
/**
 * Opvultekst (lorem ipsum): de zinnen en het zoeken naar achtergebleven opvultekst.
 *
 * Gedeeld door de module Lorem ipsum (die de zinnen invoegt) en de Oplevercheck (die kijkt of
 * er nog opvultekst op de site staat). Staat hier en niet in de module, zodat de controle ook
 * werkt als de module bij oplevering al uit staat.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Woorden waaraan opvultekst te herkennen is. Latijn dat in Nederlandse of Engelse tekst niet
 * voorkomt, ook niet als deel van een woord. Elke zin hieronder bevat er minstens één.
 */
function dp_toolbox_opvultekst_kenmerken() {
    return [
        'lorem', 'ipsum', 'consectetur', 'adipiscing', 'eiusmod', 'incididunt', 'ullamco',
        'reprehenderit', 'pariatur', 'cupidatat', 'laborum', 'pellentesque', 'vestibulum',
        'curabitur', 'aliquam', 'tincidunt', 'fermentum', 'venenatis', 'porttitor',
    ];
}

function dp_toolbox_opvultekst_zinnen() {
    return [
        'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
        'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
        'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
        'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
        'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
        'Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas.',
        'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae.',
        'Curabitur pretium tincidunt lacus, nulla gravida orci a odio.',
        'Aliquam erat volutpat, nam dui mi, tincidunt quis, accumsan porttitor, facilisis luctus, metus.',
        'Nullam varius, turpis et commodo pharetra, est eros bibendum elit, nec luctus magna felis fermentum mauris.',
        'Integer in mauris eu nibh euismod gravida, praesent venenatis metus at tortor pulvinar varius.',
        'Donec fermentum, sem at bibendum ultricies, nibh purus facilisis quam, a cursus lacus mi ut lectus.',
        'Phasellus ultrices nulla quis nibh, quisque a lectus et nunc pellentesque tincidunt.',
        'Morbi in sem quis dui placerat ornare, pellentesque odio nisi euismod in pharetra a ultricies in diam.',
        'Sed arcu nunc, cras consequat vestibulum eget, ultricies ac neque.',
        'Fusce lacinia arcu et nulla, nulla aliquam ligula eget ante porttitor accumsan.',
        'Mauris placerat eleifend leo, quisque sit amet est et sapien ullamcorper pharetra.',
        'Etiam ultricies nisi vel augue, curabitur ullamcorper ultricies nisi.',
        'Nam eget dui, etiam rhoncus, maecenas tempus tellus eget condimentum rhoncus, sem quam semper libero sit amet adipiscing sem neque sed ipsum.',
        'Aenean commodo ligula eget dolor, cum sociis natoque penatibus et magnis dis parturient montes, aliquam lorem ante.',
        'Cras dapibus, vivamus elementum semper nisi, aenean vulputate eleifend tellus, consectetur in tempor.',
        'In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.',
        'Proin sapien ipsum, porta a, auctor quis, euismod ut, mi.',
        'Suspendisse potenti, maecenas tincidunt lectus eu ante fermentum, id tempor turpis porttitor.',
    ];
}

/**
 * Waar staat nog opvultekst? Zoekt in berichten, pagina's, producten, menu-items en
 * mediatitels (titel, inhoud, samenvatting), in velden (post-meta: JetEngine, Bricks, ...),
 * in termen en in instellingen. Tien minuten bewaard; na het opslaan van een bericht opnieuw.
 *
 * @return array Lijst van [ 'titel', 'soort', 'waar' (array), 'link' ].
 */
function dp_toolbox_opvultekst_vindplaatsen( $vers = false ) {
    if ( ! $vers ) {
        $bewaard = get_transient( 'dp_toolbox_opvultekst' );
        if ( is_array( $bewaard ) ) {
            return $bewaard;
        }
    }

    global $wpdb;
    $zoek = function ( $kolom ) use ( $wpdb ) {
        $delen = [];
        foreach ( dp_toolbox_opvultekst_kenmerken() as $woord ) {
            $delen[] = $wpdb->prepare( "{$kolom} LIKE %s", '%' . $wpdb->esc_like( $woord ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return '(' . implode( ' OR ', $delen ) . ')';
    };
    $geen_types = "p.post_type NOT IN ('revision','customize_changeset','oembed_cache','user_request')";
    $geen_status = "p.post_status NOT IN ('trash','auto-draft')";

    $posts = [];
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- stukken hierboven zijn al voorbereid
    foreach ( $wpdb->get_results( "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt FROM {$wpdb->posts} p WHERE {$geen_types} AND {$geen_status} AND ( {$zoek( 'p.post_title' )} OR {$zoek( 'p.post_content' )} OR {$zoek( 'p.post_excerpt' )} ) LIMIT 300" ) as $r ) {
        foreach ( [ 'titel' => $r->post_title, 'inhoud' => $r->post_content, 'samenvatting' => $r->post_excerpt ] as $waar => $tekst ) {
            if ( dp_toolbox_opvultekst_bevat( $tekst ) ) {
                $posts[ (int) $r->ID ][ $waar ] = true;
            }
        }
    }
    foreach ( $wpdb->get_results( "SELECT DISTINCT pm.post_id, pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE {$geen_types} AND {$geen_status} AND pm.meta_key NOT IN ('_edit_lock','_edit_last','_wp_old_slug') AND {$zoek( 'pm.meta_value' )} LIMIT 500" ) as $r ) {
        $posts[ (int) $r->post_id ][ dp_toolbox_opvultekst_veldnaam( $r->meta_key ) ] = true;
    }
    $termen = $wpdb->get_results( "SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE {$zoek( 't.name' )} OR {$zoek( 'tt.description' )} LIMIT 100" );
    $opties = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND {$zoek( 'option_value' )} LIMIT 100" );
    // phpcs:enable

    $uit = [];
    foreach ( $posts as $id => $waar ) {
        $post = get_post( $id );
        if ( ! $post ) {
            continue;
        }
        $type = get_post_type_object( $post->post_type );
        $uit[] = [
            'titel' => $post->post_title !== '' ? $post->post_title : '(zonder titel, #' . $id . ')',
            'soort' => $type ? $type->labels->singular_name : $post->post_type,
            'waar'  => array_keys( $waar ),
            'link'  => dp_toolbox_opvultekst_link( $post, array_keys( $waar ) ),
        ];
    }
    foreach ( $termen as $t ) {
        $tax = get_taxonomy( $t->taxonomy );
        $uit[] = [
            'titel' => $t->name,
            'soort' => $tax ? $tax->labels->singular_name : $t->taxonomy,
            'waar'  => [ 'naam of omschrijving' ],
            'link'  => (string) get_edit_term_link( (int) $t->term_id, $t->taxonomy ),
        ];
    }
    foreach ( $opties as $naam ) {
        $uit[] = [ 'titel' => $naam, 'soort' => 'Instelling', 'waar' => [ 'wp_options' ], 'link' => '' ];
    }

    set_transient( 'dp_toolbox_opvultekst', $uit, 10 * MINUTE_IN_SECONDS );
    return $uit;
}

function dp_toolbox_opvultekst_bevat( $tekst ) {
    $tekst = strtolower( (string) $tekst );
    foreach ( dp_toolbox_opvultekst_kenmerken() as $woord ) {
        if ( strpos( $tekst, $woord ) !== false ) {
            return true;
        }
    }
    return false;
}

function dp_toolbox_opvultekst_veldnaam( $sleutel ) {
    if ( strpos( $sleutel, '_bricks_page_' ) === 0 ) {
        return 'Bricks';
    }
    return 'veld ' . $sleutel;
}

/** De plek om het aan te passen: Bricks-builder, FluentCart-editor, menu's of het bewerkscherm. */
function dp_toolbox_opvultekst_link( $post, $waar ) {
    if ( in_array( 'Bricks', $waar, true ) && count( $waar ) === 1 ) {
        return add_query_arg( 'bricks', 'run', get_permalink( $post ) );
    }
    if ( $post->post_type === 'nav_menu_item' ) {
        return admin_url( 'nav-menus.php' );
    }
    if ( $post->post_type === 'fluent-products' ) {
        return admin_url( 'admin.php?page=fluent-cart#/products/' . $post->ID );
    }
    return (string) get_edit_post_link( $post->ID, 'raw' );
}

/* Na het opslaan opnieuw zoeken (Bricks slaat op via post-meta, zonder save_post). */
add_action( 'save_post', fn() => delete_transient( 'dp_toolbox_opvultekst' ) );
add_action( 'deleted_post', fn() => delete_transient( 'dp_toolbox_opvultekst' ) );
add_action( 'edited_term', fn() => delete_transient( 'dp_toolbox_opvultekst' ) );
add_action( 'updated_post_meta', function ( $meta_id, $post_id, $sleutel ) {
    if ( strpos( (string) $sleutel, '_edit_' ) !== 0 ) {
        delete_transient( 'dp_toolbox_opvultekst' );
    }
}, 10, 3 );
