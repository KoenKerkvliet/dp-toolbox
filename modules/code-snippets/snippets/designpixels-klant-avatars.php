<?php
/**
 * Name: Design Pixels — klant-avatars homepage
 * Description: Shortcode [dpx_klant_avatars] die de gestapelde rondjes in de hero vult vanuit de klanten-CPT, in plaats van hardgecodeerde letters.
 * Sites: designpixels.nl
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initiaal uit een klantnaam.
 *
 * Slaat Nederlandse lidwoorden over: "de Schatgraver" wordt S, niet d.
 * Bij een naam die met een cijfer of leesteken begint valt hij terug op de
 * eerste letter die hij wél vindt.
 */
function dpx_klant_initiaal( $naam ) {
	$naam = trim( wp_strip_all_tags( (string) $naam ) );
	if ( '' === $naam ) {
		return '';
	}

	$overslaan = array( 'de', 'het', 'een', "'t", 'the', 'van', 'der', 'den' );
	$woorden   = preg_split( '/\s+/u', $naam );

	foreach ( $woorden as $woord ) {
		$schoon = preg_replace( '/[^\p{L}\p{N}]/u', '', $woord );
		if ( '' === $schoon ) {
			continue;
		}
		if ( in_array( mb_strtolower( $schoon ), $overslaan, true ) ) {
			continue;
		}
		return mb_strtoupper( mb_substr( $schoon, 0, 1 ) );
	}

	// Alles overgeslagen: pak dan toch de eerste letter van de hele naam.
	$schoon = preg_replace( '/[^\p{L}\p{N}]/u', '', $naam );
	return '' !== $schoon ? mb_strtoupper( mb_substr( $schoon, 0, 1 ) ) : '';
}

/**
 * [dpx_klant_avatars aantal="3" type="letters"]
 *
 * type="letters" toont initialen, type="logos" toont de uitgelichte afbeelding.
 * Let op bij logos: de klantlogo's zijn liggend (150x100). In een rond masker
 * werkt dat alleen met een lichte achtergrond en object-fit: contain — zie de
 * klasse .hm-avatars--logos in de global stylesheet.
 */
function dpx_klant_avatars_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'aantal'  => 3,
			'type'    => 'letters',
			'orderby' => 'menu_order title',
			'order'   => 'ASC',
		),
		$atts,
		'dpx_klant_avatars'
	);

	$aantal = max( 1, min( 6, absint( $atts['aantal'] ) ) );

	if ( ! post_type_exists( 'klanten' ) ) {
		return '';
	}

	$totaal = (int) wp_count_posts( 'klanten' )->publish;

	$logos = ( 'logos' === $atts['type'] );

	/*
	 * Ruimer ophalen dan nodig, want bij initialen slaan we dubbele letters over.
	 * Met de huidige klantenlijst zou de eerste drie anders "A A B" opleveren —
	 * twee identieke rondjes naast elkaar oogt als een fout, niet als een keuze.
	 */
	$kandidaten = get_posts(
		array(
			'post_type'        => 'klanten',
			'post_status'      => 'publish',
			'numberposts'      => $logos ? $aantal : 30,
			'orderby'          => sanitize_text_field( $atts['orderby'] ),
			'order'            => 'DESC' === strtoupper( $atts['order'] ) ? 'DESC' : 'ASC',
			'suppress_filters' => false,
		)
	);

	if ( ! $kandidaten ) {
		return '';
	}

	$klanten = array();
	$gezien  = array();

	foreach ( $kandidaten as $kandidaat ) {
		if ( ! $logos ) {
			$initiaal = dpx_klant_initiaal( $kandidaat->post_title );
			if ( '' === $initiaal || isset( $gezien[ $initiaal ] ) ) {
				continue;
			}
			$gezien[ $initiaal ] = true;
		}
		$klanten[] = $kandidaat;
		if ( count( $klanten ) >= $aantal ) {
			break;
		}
	}

	if ( ! $klanten ) {
		return '';
	}

	$html = '';

	foreach ( $klanten as $klant ) {
		$naam = $klant->post_title;

		if ( $logos && has_post_thumbnail( $klant->ID ) ) {
			$html .= '<span class="hm-avatars__item hm-avatars__item--logo" title="' . esc_attr( $naam ) . '">'
				. get_the_post_thumbnail( $klant->ID, 'thumbnail', array( 'alt' => esc_attr( $naam ), 'loading' => 'lazy' ) )
				. '</span>';
			continue;
		}

		$html .= '<span class="hm-avatars__item" title="' . esc_attr( $naam ) . '">'
			. esc_html( dpx_klant_initiaal( $naam ) )
			. '</span>';
	}

	/*
	 * Het plusje telt door op het échte aantal klanten, niet op wat er na het
	 * ontdubbelen overbleef — anders klopt "+6" niet meer met je portfolio.
	 */
	$rest = $totaal - count( $klanten );
	if ( $rest > 0 ) {
		$html .= '<span class="hm-avatars__item hm-avatars__item--rest">+' . (int) $rest . '</span>';
	}

	return $html;
}
add_shortcode( 'dpx_klant_avatars', 'dpx_klant_avatars_shortcode' );
