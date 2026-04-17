<?php
/**
 * Module Name: Security Headers
 * Description: Voegt belangrijke HTTP-beveiligingsheaders toe aan alle responses.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Available security headers with defaults.
 */
function dp_toolbox_sh_get_available_headers() {
    return [
        'x_frame_options' => [
            'label'   => 'X-Frame-Options',
            'desc'    => 'Voorkomt dat je site in een iframe wordt geladen (clickjacking bescherming).',
            'header'  => 'X-Frame-Options',
            'value'   => 'SAMEORIGIN',
        ],
        'x_content_type' => [
            'label'   => 'X-Content-Type-Options',
            'desc'    => 'Voorkomt MIME-type sniffing door browsers.',
            'header'  => 'X-Content-Type-Options',
            'value'   => 'nosniff',
        ],
        'referrer_policy' => [
            'label'   => 'Referrer-Policy',
            'desc'    => 'Beperkt welke informatie wordt meegestuurd bij uitgaande links.',
            'header'  => 'Referrer-Policy',
            'value'   => 'strict-origin-when-cross-origin',
        ],
        'permissions_policy' => [
            'label'   => 'Permissions-Policy',
            'desc'    => 'Beperkt browser-API toegang (camera, microfoon, geolocatie).',
            'header'  => 'Permissions-Policy',
            'value'   => 'camera=(), microphone=(), geolocation=()',
        ],
        'strict_transport' => [
            'label'   => 'Strict-Transport-Security (HSTS)',
            'desc'    => 'Forceert HTTPS-verbinding voor alle toekomstige bezoeken (1 jaar).',
            'header'  => 'Strict-Transport-Security',
            'value'   => 'max-age=31536000; includeSubDomains',
        ],
        'x_xss_protection' => [
            'label'   => 'X-XSS-Protection',
            'desc'    => 'Activeert de XSS-filter in oudere browsers.',
            'header'  => 'X-XSS-Protection',
            'value'   => '1; mode=block',
        ],
    ];
}

function dp_toolbox_sh_get_enabled() {
    return (array) get_option( 'dp_toolbox_security_headers', [] );
}

/**
 * Send enabled security headers.
 */
add_action( 'send_headers', function () {
    if ( is_admin() ) return;

    $enabled  = dp_toolbox_sh_get_enabled();
    $headers  = dp_toolbox_sh_get_available_headers();

    foreach ( $enabled as $key ) {
        if ( isset( $headers[ $key ] ) ) {
            header( $headers[ $key ]['header'] . ': ' . $headers[ $key ]['value'] );
        }
    }
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
