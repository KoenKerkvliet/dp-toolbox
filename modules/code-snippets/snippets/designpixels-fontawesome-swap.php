<?php
/**
 * Name: Design Pixels — Font Awesome font-display: swap
 * Description: Bricks zet font-display:block op zijn Font Awesome @font-face-regels, wat tekst laat wachten op het icoonfont (FOIT) en de First Contentful Paint vertraagt. Deze snippet schrijft dezelfde @font-face-regels opnieuw met font-display:swap; omdat de laatste declaratie wint, geldt swap. De URL's zijn identiek aan die van Bricks, dus de browser haalt niets extra's op.
 * Sites: designpixels.nl
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_head', function () {
    $base = get_template_directory_uri() . '/assets/fonts/fontawesome';
    ?>
<style id="dp-fa-display-swap">
@font-face{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:400;font-display:swap;src:url(<?php echo esc_url( $base . '/fa-regular-400.woff2' ); ?>) format("woff2"),url(<?php echo esc_url( $base . '/fa-regular-400.ttf' ); ?>) format("truetype")}
@font-face{font-family:"Font Awesome 6 Solid";font-style:normal;font-weight:900;font-display:swap;src:url(<?php echo esc_url( $base . '/fa-solid-900.woff2' ); ?>) format("woff2"),url(<?php echo esc_url( $base . '/fa-solid-900.ttf' ); ?>) format("truetype")}
@font-face{font-family:"Font Awesome 6 Brands";font-style:normal;font-weight:400;font-display:swap;src:url(<?php echo esc_url( $base . '/fa-brands-400.woff2' ); ?>) format("woff2"),url(<?php echo esc_url( $base . '/fa-brands-400.ttf' ); ?>) format("truetype")}
</style>
    <?php
}, 999 );
