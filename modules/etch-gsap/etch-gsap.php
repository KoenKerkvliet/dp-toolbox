<?php
/**
 * Module Name: Etch GSAP
 * Description: Laadt GSAP + ScrollTrigger op Etch-sites en biedt een kleine set scroll-animaties via data-attributen (data-dp-anim, data-dp-stagger). Alleen in te schakelen wanneer Etch actief is; op Bricks-sites wordt er niets geladen.
 * Version: 1.0.0
 *
 * Meegeleverde software van derden: assets/gsap.min.js en assets/ScrollTrigger.min.js
 * zijn GSAP 3.13.0, © GreenSock, onder de GSAP-standaardlicentie
 * (https://gsap.com/standard-license) — niet onder de GPL van deze plugin.
 * Gratis, ook commercieel. Bij een GSAP-update: beide bestanden vervangen en het
 * versienummer in wp_enqueue_script() hieronder bijwerken.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tweede slot naast de UI-gate: mocht deze module ooit als ingeschakeld in de
 * database staan terwijl Etch verdwenen is (site omgebouwd naar Bricks,
 * plugin verwijderd), dan laadt er alsnog niets.
 */
if ( ! function_exists( 'dp_toolbox_etch_is_available' ) || ! dp_toolbox_etch_is_available() ) {
	return;
}

/**
 * GSAP + ScrollTrigger + de eigen motion-laag inladen.
 *
 * Alles in de <head> (in_footer = false) — Etch print z'n per-element
 * script-attributen als <script type="module"> op wp_head prioriteit 99, en
 * die verwachten window.gsap. Staat GSAP in de footer, dan zijn die scripts te
 * vroeg en breken ze.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}

	$base = DP_TOOLBOX_URL . 'modules/etch-gsap/assets/';

	wp_enqueue_script( 'gsap', $base . 'gsap.min.js', [], '3.13.0', false );
	wp_enqueue_script( 'gsap-scrolltrigger', $base . 'ScrollTrigger.min.js', [ 'gsap' ], '3.13.0', false );

	/**
	 * Eén set bewegingswaarden voor de hele site. Aanpasbaar per site via:
	 *
	 *   add_filter( 'dp_motion_config', function ( $c ) {
	 *       $c['duration'] = 0.8;
	 *       return $c;
	 *   } );
	 */
	$config = apply_filters( 'dp_motion_config', [
		'duration' => 1.0,
		'ease'     => 'power2.out',
		'distance' => 24,
		'stagger'  => 0.14,
		'start'    => 'top 85%',
	] );

	wp_add_inline_script(
		'gsap-scrolltrigger',
		'window.dpMotionConfig = ' . wp_json_encode( $config ) . ';',
		'after'
	);

	wp_enqueue_script( 'dp-motion', $base . 'dp-motion.js', [ 'gsap-scrolltrigger' ], DP_TOOLBOX_VERSION, false );
	wp_enqueue_style( 'dp-motion', $base . 'dp-motion.css', [], DP_TOOLBOX_VERSION );
}, 5 );
