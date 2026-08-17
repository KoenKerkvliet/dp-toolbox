<?php
/**
 * Name: Design Pixels — FAQPage-schema
 * Description: Bouwt het FAQPage JSON-LD op /faq/ dynamisch uit de faq-items CPT, zodat een nieuwe of gewijzigde vraag automatisch in het schema landt zonder handwerk.
 * Sites: designpixels.nl,zoomthroat.s2-tastewp.com
 * Status: active
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_head', function () {
    if ( ! is_page( 'faq' ) ) {
        return;
    }

    $faqs = get_posts( [
        'post_type'      => 'faq-items',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ] );

    if ( empty( $faqs ) ) {
        return;
    }

    $main_entity = [];
    foreach ( $faqs as $faq ) {
        $answer_html = apply_filters( 'the_content', $faq->post_content );
        $answer_text = trim( wp_strip_all_tags( $answer_html ) );
        if ( $answer_text === '' ) {
            continue;
        }

        $main_entity[] = [
            '@type'          => 'Question',
            'name'           => wp_strip_all_tags( $faq->post_title ),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $answer_text,
            ],
        ];
    }

    if ( empty( $main_entity ) ) {
        return;
    }

    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        '@id'        => home_url( '/faq/#faqpage' ),
        'mainEntity' => $main_entity,
    ];

    echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n</script>\n";
}, 99 );
