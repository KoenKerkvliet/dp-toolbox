<?php
/**
 * Name: Scootmobiel redesign — datamodel
 * Description: Filtertaxonomieën op het FluentCart-product (kleur, conditie, kenmerken, accu-type, aantal wielen) plus vier filters die automatisch uit de specvelden worden afgeleid (snelheid, actieradius, gebruikersgewicht, breedte). Zet ook het klassieke bewerkscherm terug, zodat de JetEngine-specvelden bereikbaar zijn.
 * Sites: kaputhobbies.s2-tastewp.com
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// FluentCart verbergt het klassieke bewerkscherm; zonder dit zijn de specvelden onbereikbaar.
add_filter( 'fluent_cart/show_standalone_product_menu', '__return_true' );

/**
 * Handmatig in te vullen taxonomieën: slug => [meervoud, enkelvoud, rewrite, hiërarchisch].
 */
function dp_sm_handmatige_taxonomieen() {
    return [
        'sm-kleur'     => [ 'Kleuren', 'Kleur', 'kleur', false ],
        'sm-conditie'  => [ 'Conditie', 'Conditie', 'conditie', true ],
        'sm-kenmerken' => [ 'Kenmerken', 'Kenmerk', 'kenmerk', true ],
        'sm-accutype'  => [ 'Accu-types', 'Accu-type', 'accu-type', true ],
        'sm-wielen'    => [ 'Aantal wielen', 'Aantal wielen', 'aantal-wielen', true ],
    ];
}

/**
 * Filters die uit een specveld volgen. Nooit met de hand invullen: ze worden
 * bij elke wijziging van het veld opnieuw berekend.
 *
 * mode bucket  = precies één term, op bereik [min, max)
 * mode minimum = elke term waarvan de drempel <= waarde (cumulatief: "minimaal 30 km")
 * mode maximum = elke term waarvan de drempel >  waarde (cumulatief: "smaller dan 70 cm")
 *
 * Cumulatief is bewust: FluentCart's winkelfilter neemt maar één waarde per
 * taxonomie mee, en zo is één aangevinkte term altijd genoeg.
 */
function dp_sm_afgeleide_filters() {
    return [
        'sm-snelheid' => [
            'label' => [ 'Snelheid', 'Snelheid', 'snelheid' ],
            'meta'  => 'sm_snelheid_kmu',
            'mode'  => 'bucket',
            'terms' => [
                'tot-10-kmu'        => [ 'Tot 10 km/u', 0, 10 ],
                '10-14-kmu'         => [ '10 – 14 km/u', 10, 15 ],
                '15-19-kmu'         => [ '15 – 19 km/u', 15, 20 ],
                '20-kmu-en-sneller' => [ '20 km/u en sneller', 20, PHP_INT_MAX ],
            ],
        ],
        'sm-actieradius' => [
            'label' => [ 'Actieradius', 'Actieradius', 'actieradius' ],
            'meta'  => 'sm_actieradius_km',
            'mode'  => 'minimum',
            'terms' => [
                'minimaal-20-km' => [ 'Minimaal 20 km', 20 ],
                'minimaal-30-km' => [ 'Minimaal 30 km', 30 ],
                'minimaal-40-km' => [ 'Minimaal 40 km', 40 ],
                'minimaal-50-km' => [ 'Minimaal 50 km', 50 ],
            ],
        ],
        'sm-gebruikersgewicht' => [
            'label' => [ 'Gebruikersgewicht', 'Gebruikersgewicht', 'gebruikersgewicht' ],
            'meta'  => 'sm_max_gebruikersgewicht_kg',
            'mode'  => 'minimum',
            'terms' => [
                'tot-120-kg' => [ 'Tot 120 kg', 120 ],
                'tot-136-kg' => [ 'Tot 136 kg', 136 ],
                'tot-150-kg' => [ 'Tot 150 kg', 150 ],
                'tot-180-kg' => [ 'Tot 180 kg', 180 ],
            ],
        ],
        'sm-breedte' => [
            'label' => [ 'Breedte', 'Breedte', 'breedte' ],
            'meta'  => 'sm_breedte_cm',
            'mode'  => 'maximum',
            'terms' => [
                'smaller-dan-60-cm' => [ 'Smaller dan 60 cm', 60 ],
                'smaller-dan-70-cm' => [ 'Smaller dan 70 cm', 70 ],
                'smaller-dan-80-cm' => [ 'Smaller dan 80 cm', 80 ],
            ],
        ],
    ];
}

// Prioriteit 11: FluentCart registreert het product-CPT zelf op init 10.
add_action( 'init', function () {
    if ( ! post_type_exists( 'fluent-products' ) ) {
        return;
    }

    foreach ( dp_sm_handmatige_taxonomieen() as $tax => $def ) {
        [ $meervoud, $enkelvoud, $rewrite, $hier ] = $def;
        register_taxonomy( $tax, 'fluent-products', [
            'labels'            => [ 'name' => $meervoud, 'singular_name' => $enkelvoud, 'menu_name' => $meervoud ],
            'public'            => true,
            'hierarchical'      => $hier,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => $rewrite ],
        ] );
    }

    foreach ( dp_sm_afgeleide_filters() as $tax => $def ) {
        [ $meervoud, $enkelvoud, $rewrite ] = $def['label'];
        register_taxonomy( $tax, 'fluent-products', [
            'labels'            => [ 'name' => $meervoud, 'singular_name' => $enkelvoud, 'menu_name' => $meervoud . ' (automatisch)' ],
            'public'            => true,
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'meta_box_cb'       => false, // afgeleid, dus geen invoer in het bewerkscherm
            'rewrite'           => [ 'slug' => $rewrite ],
        ] );
    }
}, 11 );

/**
 * Berekent de afgeleide filtertermen van één product opnieuw.
 */
function dp_sm_herbereken_filters( $post_id, $alleen_meta = null ) {
    if ( get_post_type( $post_id ) !== 'fluent-products' ) {
        return;
    }

    foreach ( dp_sm_afgeleide_filters() as $tax => $def ) {
        if ( $alleen_meta !== null && $alleen_meta !== $def['meta'] ) {
            continue;
        }
        if ( ! taxonomy_exists( $tax ) ) {
            continue;
        }

        $ruw    = get_post_meta( $post_id, $def['meta'], true );
        $waarde = is_numeric( str_replace( ',', '.', (string) $ruw ) ) ? (float) str_replace( ',', '.', (string) $ruw ) : null;
        $slugs  = [];

        if ( $waarde !== null && $waarde > 0 ) {
            foreach ( $def['terms'] as $slug => $term ) {
                $treft = false;
                if ( $def['mode'] === 'bucket' ) {
                    $treft = $waarde >= $term[1] && $waarde < $term[2];
                } elseif ( $def['mode'] === 'minimum' ) {
                    $treft = $waarde >= $term[1];
                } elseif ( $def['mode'] === 'maximum' ) {
                    $treft = $waarde < $term[1];
                }
                if ( $treft ) {
                    $slugs[] = $slug;
                }
            }
        }

        $ids = [];
        foreach ( $slugs as $slug ) {
            $bestaand = get_term_by( 'slug', $slug, $tax );
            if ( ! $bestaand ) {
                $nieuw = wp_insert_term( $def['terms'][ $slug ][0], $tax, [ 'slug' => $slug ] );
                if ( is_wp_error( $nieuw ) ) {
                    continue;
                }
                $ids[] = (int) $nieuw['term_id'];
            } else {
                $ids[] = (int) $bestaand->term_id;
            }
        }

        wp_set_object_terms( $post_id, $ids, $tax, false );
    }
}

/**
 * Herberekenen zodra een van de bronvelden verandert, via welk scherm of welke
 * route dan ook (klassiek scherm, REST, import).
 */
function dp_sm_meta_gewijzigd( $meta_id, $object_id, $meta_key ) {
    static $bron = null;
    if ( $bron === null ) {
        $bron = array_column( dp_sm_afgeleide_filters(), 'meta' );
    }
    if ( in_array( $meta_key, $bron, true ) ) {
        dp_sm_herbereken_filters( (int) $object_id, $meta_key );
    }
}
add_action( 'added_post_meta', 'dp_sm_meta_gewijzigd', 10, 3 );
add_action( 'updated_post_meta', 'dp_sm_meta_gewijzigd', 10, 3 );
add_action( 'deleted_post_meta', function ( $meta_ids, $object_id, $meta_key ) {
    dp_sm_meta_gewijzigd( 0, $object_id, $meta_key );
}, 10, 3 );
