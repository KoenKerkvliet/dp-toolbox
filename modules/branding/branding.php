<?php
/**
 * Module Name: Branding
 * Description: Geef de admin-sidebar iconen een eigen merkkleur.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Inject CSS into admin header                                       */
/* ------------------------------------------------------------------ */

add_action( 'admin_head', function () {
    $color = get_option( 'dp_toolbox_branding_color', '' );
    if ( empty( $color ) ) {
        return;
    }

    // Validate hex color
    if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
        return;
    }

    $hover_color = dp_toolbox_branding_adjust_brightness( $color, 20 );
    ?>
    <style id="dp-toolbox-branding">
        /* Sidebar menu icons */
        #adminmenu .wp-menu-image::before {
            color: <?php echo esc_attr( $color ); ?> !important;
        }

        /* Hover state */
        #adminmenu li.menu-top:hover .wp-menu-image::before,
        #adminmenu li.opensub > a.menu-top .wp-menu-image::before,
        #adminmenu li > a.menu-top:focus .wp-menu-image::before {
            color: <?php echo esc_attr( $hover_color ); ?> !important;
        }

        /* Active menu icon — iets helderder */
        #adminmenu li.current .wp-menu-image::before,
        #adminmenu li.wp-has-current-submenu .wp-menu-image::before,
        #adminmenu .wp-menu-arrow div,
        #adminmenu li.current a.menu-top,
        #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,
        #adminmenu li.wp-menu-open .wp-menu-image::before {
            color: #fff !important;
        }

        /* Custom icons (image-based) — apply via filter */
        #adminmenu .wp-menu-image img {
            filter: drop-shadow(0 0 0 <?php echo esc_attr( $color ); ?>);
        }
    </style>
    <?php
} );

/* ------------------------------------------------------------------ */
/*  Helper: adjust hex color brightness                                */
/* ------------------------------------------------------------------ */

function dp_toolbox_branding_adjust_brightness( $hex, $steps ) {
    $hex = ltrim( $hex, '#' );
    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );

    $r = max( 0, min( 255, $r + $steps ) );
    $g = max( 0, min( 255, $g + $steps ) );
    $b = max( 0, min( 255, $b + $steps ) );

    return '#' . str_pad( dechex( $r ), 2, '0', STR_PAD_LEFT )
               . str_pad( dechex( $g ), 2, '0', STR_PAD_LEFT )
               . str_pad( dechex( $b ), 2, '0', STR_PAD_LEFT );
}

/* ------------------------------------------------------------------ */
/*  Admin page                                                         */
/* ------------------------------------------------------------------ */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
