<?php
/**
 * Name: Evenement dynamic data tags
 * Description: Registreert Bricks dynamic data tags voor het 'evenementen' CPT — formatteert de unix timestamp van het 'datum' meta-veld als Nederlandse datum/tijd. Tags: {event_datum} (lange datum), {event_datum_kort} (korte datum), {event_tijd} (uur:min), {event_locatie} (passthrough met fallback).
 * Sites: preciousduck.s5-tastewp.com
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registreer de tags zodat Bricks ze in de dynamic-data picker laat zien én hun output kan resolven.
 */
add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
    $tags[] = [ 'name' => '{event_datum}',       'label' => 'Evenement: datum (lang)',  'group' => 'Evenement' ];
    $tags[] = [ 'name' => '{event_datum_kort}',  'label' => 'Evenement: datum (kort)',  'group' => 'Evenement' ];
    $tags[] = [ 'name' => '{event_tijd}',        'label' => 'Evenement: tijd',          'group' => 'Evenement' ];
    $tags[] = [ 'name' => '{event_locatie}',     'label' => 'Evenement: locatie',       'group' => 'Evenement' ];
    return $tags;
} );

/**
 * Resolve één enkele tag (zonder context — wordt voor each tag occurrence aangeroepen).
 */
add_filter( 'bricks/dynamic_data/render_tag', function ( $tag, $post, $context = 'text' ) {
    $post_id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
    if ( ! $post_id || get_post_type( $post_id ) !== 'evenementen' ) {
        return $tag;
    }

    $supported = [ 'event_datum', 'event_datum_kort', 'event_tijd', 'event_locatie' ];
    $name      = trim( $tag, '{}' );
    if ( ! in_array( $name, $supported, true ) ) {
        return $tag;
    }

    if ( $name === 'event_locatie' ) {
        return get_post_meta( $post_id, 'locatie', true ) ?: '';
    }

    $ts = (int) get_post_meta( $post_id, 'datum', true );
    if ( $ts <= 0 ) return '';

    switch ( $name ) {
        case 'event_datum':
            return wp_date( 'l j F Y', $ts );          // dinsdag 13 juni 2026
        case 'event_datum_kort':
            return wp_date( 'j F Y', $ts );            // 13 juni 2026
        case 'event_tijd':
            return wp_date( 'H:i', $ts );              // 19:30
    }
    return $tag;
}, 10, 3 );

/**
 * Resolve all-tags-in-content variant — voor velden waar meerdere tags door elkaar staan
 * (bijv. een text-element met "{event_datum} · {event_locatie}").
 */
add_filter( 'bricks/dynamic_data/render_content', function ( $content, $post, $context = 'text' ) {
    if ( ! is_string( $content ) || strpos( $content, '{event_' ) === false ) {
        return $content;
    }

    $post_id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
    if ( ! $post_id || get_post_type( $post_id ) !== 'evenementen' ) {
        return $content;
    }

    $ts      = (int) get_post_meta( $post_id, 'datum', true );
    $locatie = (string) get_post_meta( $post_id, 'locatie', true );

    $replacements = [
        '{event_datum}'      => $ts > 0 ? wp_date( 'l j F Y', $ts ) : '',
        '{event_datum_kort}' => $ts > 0 ? wp_date( 'j F Y', $ts ) : '',
        '{event_tijd}'       => $ts > 0 ? wp_date( 'H:i', $ts ) : '',
        '{event_locatie}'    => $locatie,
    ];

    return strtr( $content, $replacements );
}, 10, 3 );
