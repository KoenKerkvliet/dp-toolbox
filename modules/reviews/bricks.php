<?php
/**
 * Registratie van de twee Bricks-elementen.
 *
 * Bricks laadt de bestanden zelf pas wanneer het een element nodig heeft, dus
 * hier geven we alleen paden en klassenamen door. Dat moet op `init`: eerder
 * bestaat \Bricks\Elements nog niet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	if ( ! class_exists( '\Bricks\Elements' ) ) {
		return;
	}

	\Bricks\Elements::register_element(
		__DIR__ . '/bricks/element-reviews.php',
		'dp-reviews',
		'DP_Reviews_Bricks_Element'
	);

	\Bricks\Elements::register_element(
		__DIR__ . '/bricks/element-reviews-summary.php',
		'dp-reviews-summary',
		'DP_Reviews_Summary_Bricks_Element'
	);
}, 20 );
