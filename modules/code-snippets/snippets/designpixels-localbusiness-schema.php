<?php
/**
 * Name: Design Pixels — LocalBusiness-schema
 * Description: Zet het LocalBusiness JSON-LD in de <head> van designpixels.nl: adres, telefoon, openingstijden, servicegebied en socialprofielen. Deelt bewust hetzelfde @id als het Organization-blok van The SEO Framework, zodat beide nodes bij het uitlezen tot één entiteit samensmelten. Aanpassen doe je in dp_localbusiness_data() — de rest volgt automatisch.
 * Sites: designpixels.nl,zoomthroat.s2-tastewp.com
 * Status: active
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bedrijfsgegevens op één plek.
 */
function dp_localbusiness_data() {
    return [
        'name'        => 'Design Pixels',
        'description' => 'WordPress webdesign, onderhoud en hosting voor ondernemers in Parkstad.',
        'url'         => 'https://designpixels.nl',
        'logo'        => 'https://designpixels.nl/wp-content/uploads/2025/01/Design-Pixels-logo.webp',
        'image'       => 'https://designpixels.nl/wp-content/uploads/2025/01/Design-Pixels-logo.webp',
        'telephone'   => '+31645352487',
        'email'       => 'info@designpixels.nl',
        'priceRange'  => '€€',
        'address'     => [
            'streetAddress'   => 'Aan de put 44',
            'addressLocality' => 'Landgraaf',
            'postalCode'      => '6373 VT',
            'addressCountry'  => 'NL',
        ],
        // Maandag t/m vrijdag 10:00–17:00, zaterdag 10:00–14:00
        'openingHours' => [
            [ 'days' => [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ], 'opens' => '10:00', 'closes' => '17:00' ],
            [ 'days' => [ 'Saturday' ], 'opens' => '10:00', 'closes' => '14:00' ],
        ],
        'areaServed'  => [ 'Landgraaf', 'Heerlen', 'Kerkrade', 'Brunssum', 'Parkstad', 'Zuid-Limburg' ],
        /**
         * sameAs koppelt dit bedrijf aan profielen elders. Hoe completer, hoe
         * makkelijker zoekmachines de losse vermeldingen als één entiteit zien.
         * De maps-URL is de CID-vorm van het Google Bedrijfsprofiel.
         */
        'sameAs'      => [
            'https://www.facebook.com/profile.php?id=100083360737854',
            'https://www.linkedin.com/in/koen-kerkvliet/',
            'https://www.google.com/maps?cid=17479068270188498647',
        ],
    ];
}

add_action( 'wp_head', function () {
    $d = dp_localbusiness_data();

    $opening_hours = [];
    foreach ( $d['openingHours'] as $oh ) {
        $opening_hours[] = [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => $oh['days'],
            'opens'     => $oh['opens'],
            'closes'    => $oh['closes'],
        ];
    }

    $area_served = array_map( function ( $place ) {
        return [ '@type' => 'AdministrativeArea', 'name' => $place ];
    }, $d['areaServed'] );

    $address = array_filter( [
        '@type'           => 'PostalAddress',
        'streetAddress'   => $d['address']['streetAddress'],
        'addressLocality' => $d['address']['addressLocality'],
        'postalCode'      => $d['address']['postalCode'],
        'addressCountry'  => $d['address']['addressCountry'],
    ] );

    $schema = [
        '@context'                  => 'https://schema.org',
        '@type'                     => 'LocalBusiness',
        '@id'                       => $d['url'] . '/#/schema/Organization',
        'name'                      => $d['name'],
        'description'               => $d['description'],
        'url'                       => $d['url'],
        'logo'                      => $d['logo'],
        'image'                     => $d['image'],
        'telephone'                 => $d['telephone'],
        'email'                     => $d['email'],
        'priceRange'                => $d['priceRange'],
        'address'                   => $address,
        'openingHoursSpecification' => $opening_hours,
        'areaServed'                => $area_served,
    ];

    if ( ! empty( $d['sameAs'] ) ) {
        $schema['sameAs'] = array_values( $d['sameAs'] );
    }

    echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n</script>\n";
}, 99 );
