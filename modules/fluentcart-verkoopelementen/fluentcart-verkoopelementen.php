<?php
/**
 * Module Name: FluentCart Verkoopelementen
 * Description: De kleine dingen die een winkel verkopend maken, naar het model van bol.com: badges op de productkaarten (Aanbieding, Nieuw, nog N op voorraad), kleurstalen bij producten die in meerdere kleuren te koop zijn, een bezorgbelofte boven de koopknop en een vinkjeslijst eronder, en het voorraadlabel onder de prijs. Elk onderdeel apart aan of uit.
 * Category: ecommerce
 * Requires: fluent-cart
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DP_FCVE_VERSION', '1.1.0' );

function dp_fcve_standaarden() {
	return [
		'badges'        => true,
		'kleurstalen'   => true,
		'voorraadlabel' => true,
		'bezorging'     => true,
		'usp'           => true,
	];
}

function dp_fcve_instellingen() {
	$opgeslagen = get_option( 'dp_fc_verkoopelementen', null );

	if ( ! is_array( $opgeslagen ) ) {
		return dp_fcve_standaarden();
	}

	return array_merge( dp_fcve_standaarden(), $opgeslagen );
}

function dp_fcve_aan( $onderdeel ) {
	$instellingen = dp_fcve_instellingen();

	return ! empty( $instellingen[ $onderdeel ] );
}

/**
 * Regels als "slug: Label" omzetten in paren. Zelfde notatie als de
 * specificatiemodule, zodat je maar één invoervorm hoeft te onthouden.
 */
function dp_fcve_regels_naar_paren( $ruw ) {
	$paren = [];

	foreach ( preg_split( '/\R/', (string) $ruw ) as $regel ) {
		$regel = trim( $regel );

		if ( $regel === '' ) {
			continue;
		}

		$pos = strpos( $regel, ':' );

		if ( $pos === false ) {
			continue;
		}

		$sleutel = trim( substr( $regel, 0, $pos ) );
		$waarde  = trim( substr( $regel, $pos + 1 ) );

		if ( $sleutel === '' || $waarde === '' ) {
			continue;
		}

		$paren[ $sleutel ] = $waarde;
	}

	return $paren;
}

function dp_fcve_lijst( $ruw ) {
	$uit = [];

	foreach ( preg_split( '/\R/', (string) $ruw ) as $regel ) {
		$regel = trim( $regel );

		if ( $regel !== '' ) {
			$uit[] = $regel;
		}
	}

	return $uit;
}

/* ================================================================== */
/*  1. Badges op productkaarten                                        */
/* ================================================================== */

/**
 * Een badge-rij linksboven op elke productkaart, overal waar FluentCart een
 * kaart rendert: winkelpagina, categoriearchieven, zoekresultaten, rails op de
 * homepage en het Bricks-element (dat via de dynamic tag {fct_product_image}
 * dezelfde renderer gebruikt).
 *
 * Alles wordt uit de data afgeleid, nooit uit een lijst met productnamen — een
 * badge die niet klopt is erger dan geen badge.
 */

/**
 * Aanbieding: exact dezelfde voorwaarde die FluentCart zelf gebruikt voor de
 * doorgestreepte prijs (ProductCardRender::renderPrices), zodat badge en prijs
 * elkaar nooit tegenspreken.
 *
 * Alleen simpele producten: bij varianten toont de kaart zelf ook geen
 * vergelijkingsprijs, dus een badge zou daar misleidend zijn.
 */
function dp_fcve_is_aanbieding( $product ) {
	if ( ! $product || empty( $product->detail ) ) {
		return false;
	}

	if ( $product->detail->variation_type !== 'simple' ) {
		return false;
	}

	$variant = $product->variants ? $product->variants->first() : null;

	if ( ! $variant ) {
		return false;
	}

	$prijs     = (int) $variant->item_price;
	$van_prijs = (int) $variant->compare_price;

	return $van_prijs > 0 && $van_prijs > $prijs;
}

/**
 * Aantal stuks dat nog te koop is, of null wanneer er geen voorraad bijgehouden
 * wordt of de drempel niet gehaald wordt.
 */
function dp_fcve_lage_voorraad( $product ) {
	$drempel = (int) apply_filters( 'dp_fc_low_stock_threshold', (int) get_option( 'dp_fc_badges_voorraaddrempel', 5 ), $product );

	if ( $drempel < 1 || ! $product || ! $product->variants ) {
		return null;
	}

	$totaal  = 0;
	$bewaakt = false;

	foreach ( $product->variants as $variant ) {
		if ( empty( $variant->manage_stock ) ) {
			continue;
		}

		$bewaakt = true;
		$totaal += max( 0, (int) $variant->available );
	}

	if ( ! $bewaakt || $totaal < 1 || $totaal > $drempel ) {
		return null;
	}

	return $totaal;
}

if ( dp_fcve_aan( 'badges' ) ) {

	add_action( 'fluent_cart/product/group/before_image_block', function ( $context ) {
		$product = is_array( $context ) ? ( $context['product'] ?? null ) : null;

		if ( ! $product ) {
			return;
		}

		$product_id = (int) ( $product->ID ?? 0 );
		$badges     = [];

		if ( dp_fcve_is_aanbieding( $product ) ) {
			$label    = (string) get_option( 'dp_fc_badges_aanbieding_label', 'Aanbieding' );
			$badges[] = [ 'sale', apply_filters( 'dp_fc_sale_badge_label', $label !== '' ? $label : 'Aanbieding', $product ) ];
		}

		if ( $product_id ) {
			$uit_taxonomie = dp_fcve_regels_naar_paren(
				get_option( 'dp_fc_badges_categorieen', "nieuw: Nieuw\nkleine-oplage: Kleine oplage" )
			);

			$soort = 0;

			foreach ( $uit_taxonomie as $slug => $label ) {
				if ( has_term( $slug, 'product-categories', $product_id ) ) {
					// De eerste categoriebadge krijgt de opvallende stijl, de rest de rustige.
					$badges[] = [ $soort === 0 ? 'new' : 'limited', $label ];
					$soort++;
				}
			}
		}

		$voorraad = dp_fcve_lage_voorraad( $product );

		if ( $voorraad !== null ) {
			$badges[] = [
				'stock',
				$voorraad === 1
					? (string) get_option( 'dp_fc_badges_laatste_label', 'Laatste exemplaar' )
					: sprintf( (string) get_option( 'dp_fc_badges_voorraad_label', 'Nog %d op voorraad' ), $voorraad ),
			];
		}

		$badges = apply_filters( 'dp_fc_card_badges', $badges, $product );

		if ( ! is_array( $badges ) || ! $badges ) {
			return;
		}

		// Meer dan drie labels op een kaart van 300px leest niet meer.
		$badges = array_slice( $badges, 0, 3 );

		echo '<div class="dp-fc-badges">';

		foreach ( $badges as $badge ) {
			printf(
				'<span class="dp-fc-badge dp-fc-badge--%1$s">%2$s</span>',
				esc_attr( $badge[0] ),
				esc_html( $badge[1] )
			);
		}

		echo '</div>';
	} );

	add_action( 'wp_head', function () {
		?>
<style id="dp-fc-badges-css">
/* De badge-rij staat in de kaart, vlak voor de afbeelding; de kaart moet dus een
   positioneringscontext zijn. pointer-events uit, anders vangt de rij de klik op
   de productfoto op. */
.fct-product-card { position: relative; }
.dp-fc-badges {
	position: absolute;
	z-index: 2;
	inset-block-start: var(--space-xs, .5rem);
	inset-inline: var(--space-xs, .5rem);
	display: flex;
	flex-wrap: wrap;
	gap: .25rem;
	pointer-events: none;
}
.dp-fc-badge {
	padding: .25em .6em;
	border-radius: var(--radius-s, 4px);
	background: var(--white, #fff);
	color: var(--base, #1a1a1a);
	font-size: var(--text-xs, .75rem);
	font-weight: 600;
	letter-spacing: .04em;
	line-height: 1.4;
	box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.dp-fc-badge--sale    { background: var(--primary, #8a5a30); color: var(--white, #fff); }
.dp-fc-badge--new     { background: var(--base, #1a1a1a);    color: var(--white, #fff); }
.dp-fc-badge--limited { background: var(--base-light, #ece7e2); color: var(--base, #1a1a1a); }
.dp-fc-badge--stock   { background: var(--white, #fff);      color: var(--primary-dark, #6d4522); }
</style>
		<?php
	}, 20 );
}

/* ================================================================== */
/*  2. Kleurstalen op productkaarten                                   */
/* ================================================================== */

/**
 * Bolletjes linksonder op de productfoto met de kleuren waaruit de klant kan
 * kiezen. Alleen bij producten met minstens twee kleurvarianten.
 *
 * Bron zijn uitsluitend FluentCarts eigen varianten (advanced variations met een
 * attribuutgroep van het type "color"). De kleurtaxonomie van het winkelfilter
 * telt bewust niet mee: die zegt hoe een product eruitziet, niet waaruit je kunt
 * kiezen — een tweekleurige vaas is niet "in twee kleuren verkrijgbaar".
 */

/**
 * Alle kleurtermen uit attribuutgroepen van het type "color", als
 * term-id => [ naam, hex ]. Eén query per request, hoeveel kaarten er ook staan.
 */
function dp_fcve_variant_kleurtermen() {
	static $termen = null;

	if ( $termen !== null ) {
		return $termen;
	}

	global $wpdb;

	$termen = [];
	$rijen  = $wpdb->get_results(
		"SELECT t.id, t.title, t.settings AS term_settings, g.settings AS groep_settings
		 FROM {$wpdb->prefix}fct_atts_terms t
		 INNER JOIN {$wpdb->prefix}fct_atts_groups g ON g.id = t.group_id"
	);

	foreach ( (array) $rijen as $rij ) {
		$groep = json_decode( (string) $rij->groep_settings, true );

		if ( ! is_array( $groep ) || ( $groep['type'] ?? '' ) !== 'color' ) {
			continue;
		}

		$term = json_decode( (string) $rij->term_settings, true );
		$hex  = is_array( $term ) ? sanitize_hex_color( (string) ( $term['color'] ?? '' ) ) : '';

		if ( $hex ) {
			$termen[ (int) $rij->id ] = [ (string) $rij->title, $hex ];
		}
	}

	return $termen;
}

/**
 * De kiesbare kleuren van een product, in de volgorde van de varianten (dus de
 * standaardkleur eerst), als lijst van [ naam, hex ].
 *
 * Leest alleen wat ShopResource al eager-laadt: `variation_identifier` is de
 * underscore-join van de term-id's van een variant. Geen query per kaart.
 */
function dp_fcve_kaart_kleuren( $product ) {
	$kleuren = [];

	if ( ! $product || empty( $product->detail ) || empty( $product->variants ) ) {
		return $kleuren;
	}

	if ( $product->detail->variation_type === 'advanced_variations' ) {
		$termen    = dp_fcve_variant_kleurtermen();
		$varianten = [];

		foreach ( $product->variants as $variant ) {
			// Een uitgeschakelde variant is niet te koop, dus ook geen keuze.
			if ( isset( $variant->item_status ) && $variant->item_status !== 'active' ) {
				continue;
			}

			$varianten[] = $variant;
		}

		usort( $varianten, function ( $a, $b ) {
			return (int) $a->serial_index <=> (int) $b->serial_index;
		} );

		foreach ( $varianten as $variant ) {
			foreach ( explode( '_', (string) $variant->variation_identifier ) as $term_id ) {
				$term_id = (int) $term_id;

				if ( isset( $termen[ $term_id ] ) && ! isset( $kleuren[ $term_id ] ) ) {
					$kleuren[ $term_id ] = $termen[ $term_id ];
				}
			}
		}
	}

	$kleuren = apply_filters( 'dp_fc_kaart_kleuren', array_values( $kleuren ), $product );

	return is_array( $kleuren ) ? $kleuren : [];
}

if ( dp_fcve_aan( 'kleurstalen' ) ) {

	// Ná de afbeelding, zodat de rij in de flow direct onder de foto staat en er
	// met een negatieve marge overheen kan schuiven — zie de CSS.
	add_action( 'fluent_cart/product/group/after_image_block', function ( $context ) {
		$product = is_array( $context ) ? ( $context['product'] ?? null ) : null;
		$kleuren = dp_fcve_kaart_kleuren( $product );

		// Eén kleur is geen keuze; die zie je al op de foto.
		if ( count( $kleuren ) < 2 ) {
			return;
		}

		// Meer bolletjes dan dit wordt een streepjescode. Daarboven: laatste plek
		// wordt "+N", zodat de rij nooit breder wordt dan bij het maximum.
		$max     = max( 2, (int) apply_filters( 'dp_fc_kleurstalen_max', 5, $product ) );
		$aantal  = count( $kleuren );
		$tonen   = $aantal > $max ? array_slice( $kleuren, 0, $max - 1 ) : $kleuren;
		$overige = $aantal - count( $tonen );
		$namen   = implode( ', ', array_map( function ( $kleur ) {
			return $kleur[0];
		}, $kleuren ) );

		printf(
			'<div class="dp-fc-kleuren" role="img" aria-label="%s">',
			esc_attr( sprintf( 'Verkrijgbaar in %1$d kleuren: %2$s', $aantal, $namen ) )
		);

		foreach ( $tonen as $kleur ) {
			printf(
				'<span class="dp-fc-kleur" style="--dp-fc-kleur:%1$s" title="%2$s"></span>',
				esc_attr( $kleur[1] ),
				esc_attr( $kleur[0] )
			);
		}

		if ( $overige > 0 ) {
			printf( '<span class="dp-fc-kleuren__meer">+%d</span>', (int) $overige );
		}

		echo '</div>';
	}, 10 );

	add_action( 'wp_head', function () {
		?>
<style id="dp-fc-kleuren-css">
/* De rij staat in de flow direct ná de productfoto. De negatieve bovenmarge
   (eigen hoogte + afstand) trekt hem over de onderrand van de foto, de
   ondermarge geeft die afstand terug: netto neemt de rij nul ruimte in. Zo
   hoeven we de wikkel rond de foto niet te kennen — die verschilt tussen
   FluentCarts eigen kaart en het Bricks-element — en schuiven titel en prijs
   niet op ten opzichte van kaarten zonder kleuren. */
.dp-fc-kleuren {
	--dp-fc-kleuren-h: 1.5rem;
	--dp-fc-kleuren-afstand: var(--space-xs, .5rem);
	position: relative;
	z-index: 2;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	gap: .3rem;
	width: max-content;
	height: var(--dp-fc-kleuren-h);
	margin: calc(-1 * (var(--dp-fc-kleuren-h) + var(--dp-fc-kleuren-afstand))) 0 var(--dp-fc-kleuren-afstand) var(--dp-fc-kleuren-afstand);
	padding-inline: .45rem;
	border-radius: 999px;
	background: rgba(255, 255, 255, .92);
	box-shadow: 0 1px 3px rgba(0, 0, 0, .18);
	line-height: 1;
}
/* Ring naar binnen, zodat roomwit en wit niet oplossen in de witte pil. */
.dp-fc-kleur {
	flex: 0 0 auto;
	width: .875rem;
	height: .875rem;
	border-radius: 50%;
	background: var(--dp-fc-kleur, #ccc);
	box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .22);
}
.dp-fc-kleuren__meer {
	font-size: var(--text-xs, .75rem);
	font-weight: 600;
	color: var(--base, #1a1a1a);
}
</style>
		<?php
	}, 20 );
}

/* ================================================================== */
/*  3, 4 en 5. Bezorgbelofte, koopvoordelen en het voorraadlabel       */
/* ================================================================== */

/**
 * Digitaal of fysiek? Staat in fct_product_details.fulfillment_type. Per request
 * gecachet, want de hooks vragen het twee keer per pagina.
 */
function dp_fcve_is_digitaal( $product_id ) {
	static $cache = [];

	$product_id = (int) $product_id;

	if ( ! $product_id ) {
		return false;
	}

	if ( ! isset( $cache[ $product_id ] ) ) {
		global $wpdb;

		$type = $wpdb->get_var( $wpdb->prepare(
			"SELECT fulfillment_type FROM {$wpdb->prefix}fct_product_details WHERE post_id = %d",
			$product_id
		) );

		$cache[ $product_id ] = ( $type === 'digital' );
	}

	return $cache[ $product_id ];
}

/**
 * Het product-id uit de hook-context peuteren. FluentCart geeft een gedecoreerde
 * array mee; valt terug op de gequeryde post als die vorm ooit verandert.
 */
function dp_fcve_product_id( $context ) {
	if ( is_array( $context ) && isset( $context['product'] ) && is_object( $context['product'] ) ) {
		return (int) ( $context['product']->ID ?? 0 );
	}

	return (int) get_queried_object_id();
}

if ( dp_fcve_aan( 'bezorging' ) ) {

	add_action( 'fluent_cart/product/single/before_actions_block', function ( $context ) {
		$product_id = dp_fcve_product_id( $context );

		$standaard = dp_fcve_is_digitaal( $product_id )
			? (string) get_option( 'dp_fc_bezorgtekst_digitaal', 'Direct na betaling te downloaden' )
			: (string) get_option( 'dp_fc_bezorgtekst', 'Voor 17.00 uur besteld, de volgende werkdag verzonden' );

		$tekst = apply_filters( 'dp_fc_bezorgtekst', $standaard, $product_id );

		if ( ! is_string( $tekst ) || trim( $tekst ) === '' ) {
			return;
		}

		printf(
			'<p class="dp-fc-bezorg"><span class="dp-fc-bezorg__icoon" aria-hidden="true"></span>%s</p>',
			esc_html( $tekst )
		);
	}, 10 );
}

if ( dp_fcve_aan( 'usp' ) ) {

	add_action( 'fluent_cart/product/single/after_actions_block', function ( $context ) {
		$product_id = dp_fcve_product_id( $context );

		// Een e-book heeft niets aan "gratis verzending". Vandaar twee lijsten;
		// het filter blijft er voor afwijkingen per product.
		$ruw = dp_fcve_is_digitaal( $product_id )
			? (string) get_option( 'dp_fc_usp_digitaal', '' )
			: (string) get_option( 'dp_fc_usp', '' );

		$items = apply_filters( 'dp_fc_usp_items', dp_fcve_lijst( $ruw ), $product_id );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}

		$regels = '';

		foreach ( $items as $item ) {
			// wp_kses_post zodat <strong> in een regel mag, zonder de deur open te zetten.
			$regels .= '<li>' . wp_kses_post( $item ) . '</li>';
		}

		printf( '<ul class="dp-fc-usp">%s</ul>', $regels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- regels zijn door wp_kses_post gehaald.
	}, 10 );
}

if ( dp_fcve_aan( 'bezorging' ) || dp_fcve_aan( 'usp' ) || dp_fcve_aan( 'voorraadlabel' ) ) {

	add_action( 'wp_head', function () {
		if ( ! is_singular( 'fluent-products' ) ) {
			return;
		}
		?>
<style id="dp-fc-koopinfo-css">
<?php if ( dp_fcve_aan( 'voorraadlabel' ) ) : ?>
/* Het voorraadlabel staat van huis uit onder de TITEL. Onder de prijs is het
   logischer — daar kijkt de klant op het moment dat hij besluit. De summary is
   van zichzelf display:block, dus flex is nodig om met order te kunnen schuiven.
   Op een zelfgebouwd sjabloon zonder .fct-product-summary is dit een no-op. */
.fct-product-summary { display: flex; flex-direction: column; align-items: stretch; }
.fct-product-summary .fct-product-meta { order: 1; }
.fct-product-summary .fct_buy_section { order: 2; }

/* "Beschikbaarheid:" ervoor maakt er een veldje van; zonder leest het als label. */
.fct-stock-label { display: none; }
<?php endif; ?>

/* De knoppenwikkel (.fct-product-buttons-wrap) is een grid van twee kolommen.
   Zonder deze regel nemen de bezorgregel en de vinkjeslijst ieder een cel in, en
   schuiven de knoppen een plek op. Over de volle breedte spannen dus. */
.dp-fc-bezorg,
.dp-fc-usp { grid-column: 1 / -1; }

.dp-fc-bezorg {
	display: flex;
	align-items: center;
	gap: .5em;
	margin-block: 0 var(--space-s, 1rem);
	font-size: var(--text-s, .9rem);
	line-height: 1.5;
	color: var(--neutral-semi-dark, #64584f);
}
.dp-fc-bezorg__icoon {
	flex: 0 0 auto;
	width: .95em;
	height: .95em;
	border-radius: 50%;
	border: 2px solid currentColor;
	opacity: .8;
}

.dp-fc-usp {
	margin-block: var(--space-m, 1.5rem) 0;
	padding-inline-start: 0;
	list-style: none;
	font-size: var(--text-s, .9rem);
	line-height: 1.55;
}
.dp-fc-usp li {
	position: relative;
	margin-block: 0 .6em;
	padding-inline-start: 1.7em;
}
.dp-fc-usp li:last-child { margin-block-end: 0; }
/* Vinkje uit twee lijntjes, zodat er geen SVG of icoonfont bij hoeft. */
.dp-fc-usp li::before,
.dp-fc-usp li::after {
	content: "";
	position: absolute;
	background: var(--success, #189877);
}
.dp-fc-usp li::before {
	inset-inline-start: .18em;
	inset-block-start: .62em;
	width: .3em;
	height: 2px;
	transform: rotate(45deg);
}
.dp-fc-usp li::after {
	inset-inline-start: .34em;
	inset-block-start: .5em;
	width: .62em;
	height: 2px;
	transform: rotate(-50deg);
}
</style>
		<?php
	}, 20 );
}

if ( is_admin() ) {
	require_once __DIR__ . '/admin-page.php';
}
