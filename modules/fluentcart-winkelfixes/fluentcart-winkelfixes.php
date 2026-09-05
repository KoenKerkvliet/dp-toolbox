<?php
/**
 * Module Name: FluentCart Winkelfixes
 * Description: Drie losse verbeteringen aan de winkel die FluentCart zelf niet levert: de productfoto laten meewisselen met de gekozen variant, srcset op productkaarten, en kleur als filterdimensie met kleurstalen. Elk onderdeel apart aan of uit.
 * Category: ecommerce
 * Requires: fluent-cart
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DP_FCWF_VERSION', '1.0.0' );

/**
 * Standaard staan de twee reparaties aan en het kleurfilter uit.
 *
 * De eerste twee repareren gedrag dat elke winkel wil; het kleurfilter voegt een
 * taxonomie toe aan de producten en is dus een keuze over het datamodel. Dat zet
 * je zelf aan, niet de plugin voor je.
 */
function dp_fcwf_standaarden() {
	return [
		'variantfoto' => true,
		'srcset'      => true,
		'kleurfilter' => false,
	];
}

function dp_fcwf_instellingen() {
	$opgeslagen = get_option( 'dp_fc_winkelfixes', null );

	if ( ! is_array( $opgeslagen ) ) {
		return dp_fcwf_standaarden();
	}

	// Nieuwe onderdelen in een latere versie krijgen hun standaard, in plaats van
	// stil uit te staan omdat ze nog niet in de opgeslagen array voorkwamen.
	return array_merge( dp_fcwf_standaarden(), $opgeslagen );
}

function dp_fcwf_aan( $onderdeel ) {
	$instellingen = dp_fcwf_instellingen();

	return ! empty( $instellingen[ $onderdeel ] );
}

/* ================================================================== */
/*  1. De productfoto volgt de gekozen variant                         */
/* ================================================================== */

/**
 * FluentCart stuurt bij het kiezen van een variant twee events naar `window`:
 * `fluentCartAdvVariationImageChanged` (met de foto-URL die bij de gekozen
 * attribuutterm hoort) en `fluentCartSingleProductVariationChanged` (met het
 * variant-id). Zijn eigen galerij luistert daar ook op — maar alleen betrouwbaar
 * in zijn eigen productsjabloon.
 *
 * Bouw je de productpagina zelf, met de galerij en het koopblok als losse
 * elementen in aparte kolommen (Bricks, Etch, een blokthema), dan komt het niet
 * aan: de galerij-controller hangt zijn luisteraars aan een AbortController die
 * in die opzet al afgebroken is voordat de bezoeker iets kiest. De events vuren
 * wél, met de juiste gegevens, en er staat geen fout in de console — de foto
 * blijft gewoon staan. Klikken op een miniatuur werkt dan nog wel, want die
 * luisteraars gaan zonder signal.
 *
 * Hieronder vangen we de events zelf op. Bewust op de URL uit het event en niet
 * op het variant-id: FluentCart ontdubbelt miniaturen op media-id, waardoor de
 * foto van de standaardvariant zijn variant-koppeling kwijtraakt zodra diezelfde
 * foto ook de eerste galerijfoto is — en dat is hij meestal, want die eerste
 * galerijfoto is tegelijk de kaartfoto in de winkel. De URL klopt altijd.
 *
 * Blijft goed als FluentCart het ooit zelf wel doet: we zetten dezelfde URL, en
 * doen niets als de foto al klopt.
 */
if ( dp_fcwf_aan( 'variantfoto' ) ) {
	add_action( 'wp_footer', function () {
		if ( is_admin() ) {
			return;
		}
		?>
<script id="dp-fc-variantfoto">
(function () {
	"use strict";

	function wisselFoto(galerij, url) {
		var hoofd = galerij.querySelector("[data-fluent-cart-single-product-page-product-thumbnail]");

		if (!hoofd) {
			return;
		}

		// Geen foto bij deze variant: terug naar de standaardfoto van het product.
		if (!url) {
			url = hoofd.getAttribute("data-default-image-url");
		}

		if (!url || hoofd.getAttribute("src") === url) {
			return;
		}

		hoofd.setAttribute("src", url);
		hoofd.removeAttribute("srcset");

		Array.prototype.forEach.call(
			galerij.querySelectorAll("[data-fluent-cart-thumb-control-button]"),
			function (knop) {
				var actief = knop.getAttribute("data-url") === url;
				knop.classList.toggle("active", actief);
				knop.setAttribute("aria-pressed", actief ? "true" : "false");
			}
		);
	}

	window.addEventListener("fluentCartAdvVariationImageChanged", function (e) {
		var detail = e.detail || {};

		Array.prototype.forEach.call(
			document.querySelectorAll("[data-fct-product-gallery]"),
			function (galerij) {
				// Op de winkelpagina kan er meer dan een galerij staan (snelweergave).
				if (String(galerij.getAttribute("data-product-id")) !== String(detail.productId)) {
					return;
				}

				wisselFoto(galerij, detail.url);
			}
		);
	});
}());
</script>
		<?php
	}, 30 );
}

/* ================================================================== */
/*  2. srcset op productkaarten                                        */
/* ================================================================== */

/**
 * ProductCardRender::renderProductImage() zet een kale <img src> neer, zonder
 * srcset, en er zit geen filter op — getThumbnailAttribute() geeft simpelweg
 * default_media['url'] terug. Een kaartje van ~300px krijgt zo het volledige
 * origineel binnen: op een echte winkel gemeten 765 KB voor één afbeelding van
 * 1400x2100, maal twaalf kaarten op de eerste pagina.
 *
 * De kaart en de productpagina delen diezelfde URL, dus de src kleiner maken zou
 * de grote productfoto onscherp maken. Daarom srcset + sizes toevoegen: de
 * browser kiest dan zelf een kleine variant voor een kaartje en het grote
 * bestand waar dat nodig is.
 *
 * De renderer biedt geen filter op de img, dus vangen we de uitvoer op tussen de
 * twee acties die er wél omheen zitten.
 *
 * LET OP: dit werkt alleen als WordPress tussenformaten aanmaakt. De module
 * WebP Converter schakelt die standaard uit — zie de waarschuwing op het
 * instellingenpaneel.
 */
if ( dp_fcwf_aan( 'srcset' ) ) {
	add_action( 'fluent_cart/product/group/before_image_block', function () {
		ob_start();
	}, 1 );

	add_action( 'fluent_cart/product/group/after_image_block', function () {
		$html = ob_get_clean();

		if ( ! is_string( $html ) ) {
			return;
		}

		echo dp_fcwf_kaart_srcset( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doorgeefluik van FluentCarts eigen markup.
	}, 99 );
}

function dp_fcwf_kaart_srcset( $html ) {
	$merk = 'fct-product-card-image';

	if ( strpos( $html, $merk ) === false || stripos( $html, 'srcset' ) !== false ) {
		return $html;
	}

	$vanaf = strpos( $html, $merk );
	$src_p = strpos( $html, ' src="', $vanaf );

	if ( $src_p === false ) {
		return $html;
	}

	$start = $src_p + 6;
	$eind  = strpos( $html, '"', $start );

	if ( $eind === false ) {
		return $html;
	}

	$url = substr( $html, $start, $eind - $start );

	// De ingebouwde placeholder is een SVG en heeft niets te kiezen.
	if ( $url === '' || substr( $url, -4 ) === '.svg' ) {
		return $html;
	}

	$id = dp_fcwf_attachment_id( $url );

	if ( ! $id ) {
		return $html;
	}

	$srcset = wp_get_attachment_image_srcset( $id, 'medium_large' );

	if ( ! $srcset ) {
		return $html;
	}

	// Standaard: 1 kolom op mobiel, 2 op tablet, ~320px daarboven. Wijkt jouw
	// raster daarvan af, dan zet je hier met het filter je eigen breedtes neer.
	$sizes = apply_filters(
		'dp_fc_kaart_sizes',
		'(max-width: 599px) 92vw, (max-width: 991px) 46vw, 320px',
		$id
	);

	$extra = ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( $sizes ) . '"';
	$html  = substr_replace( $html, $extra, $src_p, 0 );

	// Ook de src-terugval verkleinen. Srcset stuurt moderne browsers, maar de
	// preload-scanner kan de src alvast ophalen — en dat was hier het volledige
	// origineel. Een variant als terugval kost niets en scheelt dat.
	$klein = wp_get_attachment_image_url( $id, 'medium_large' );

	if ( $klein && $klein !== $url ) {
		$html = str_replace( ' src="' . $url . '"', ' src="' . esc_url( $klein ) . '"', $html );
	}

	return $html;
}

/**
 * URL naar attachment-id, met een cache per request: attachment_url_to_postid()
 * doet een query en op een winkelpagina staan al gauw twaalf kaarten.
 */
function dp_fcwf_attachment_id( $url ) {
	static $cache = [];

	if ( array_key_exists( $url, $cache ) ) {
		return $cache[ $url ];
	}

	$id = attachment_url_to_postid( $url );

	// Soms staat er een formaat-URL opgeslagen; dan zonder -600x900 proberen.
	if ( ! $id ) {
		$zonder = preg_replace( '/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $url );

		if ( $zonder && $zonder !== $url ) {
			$id = attachment_url_to_postid( $zonder );
		}
	}

	$cache[ $url ] = $id ? (int) $id : 0;

	return $cache[ $url ];
}

/* ================================================================== */
/*  3. Kleur als filterdimensie, met kleurstalen                       */
/* ================================================================== */

/**
 * De taxonomie waarin de kleuren leven.
 *
 * FluentCart heeft wél een ingebouwde Color-attribuutgroep (fct_atts_*), maar
 * die is voor varianten en verschijnt niet in de winkelfilters. De filterlijst
 * wordt opgebouwd uit get_taxonomies([object_type => fluent-products, public =>
 * true]), dus elke publieke taxonomie op het product-CPT doet automatisch mee.
 *
 * Bewust een eigen taxonomie en geen JetEngine-veld: op een FluentCart-site is
 * FluentCarts eigen model leidend, en een taxonomie kost geen extra plugin.
 */
function dp_fcwf_taxonomie() {
	return (string) apply_filters( 'dp_fc_kleur_taxonomie', 'product-colors' );
}

if ( dp_fcwf_aan( 'kleurfilter' ) ) {

	add_action( 'init', function () {
		if ( ! post_type_exists( 'fluent-products' ) ) {
			return;
		}

		register_taxonomy( dp_fcwf_taxonomie(), 'fluent-products', apply_filters( 'dp_fc_kleur_taxonomie_args', [
			'labels'            => [
				'name'          => 'Kleuren',
				'singular_name' => 'Kleur',
				'menu_name'     => 'Kleuren',
				'all_items'     => 'Alle kleuren',
				'edit_item'     => 'Kleur bewerken',
				'add_new_item'  => 'Nieuwe kleur',
			],
			'public'            => true, // verplicht, anders ziet FluentCart hem niet
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'kleur' ],
		] ) );
	}, 11 ); // NA FluentCarts CPT-registratie op init:10, anders faalt de guard hierboven

	add_action( 'wp_head', 'dp_fcwf_kleurstalen_css', 20 );

	if ( is_admin() ) {
		$taxonomie = dp_fcwf_taxonomie();

		add_action( $taxonomie . '_add_form_fields', 'dp_fcwf_term_veld_nieuw' );
		add_action( $taxonomie . '_edit_form_fields', 'dp_fcwf_term_veld_bewerken' );
		add_action( 'created_' . $taxonomie, 'dp_fcwf_term_veld_opslaan' );
		add_action( 'edited_' . $taxonomie, 'dp_fcwf_term_veld_opslaan' );
	}
}

/**
 * Terugval voor kleuren waar nog geen hex bij staat.
 *
 * Alleen om te voorkomen dat een verse winkel grijze bolletjes toont: zodra je
 * bij een kleur een hex invult wint die. Aan te vullen met `dp_fc_kleur_hexen`.
 */
function dp_fcwf_terugval_hexen() {
	return [
		'wit'        => '#FFFFFF',
		'roomwit'    => '#F2EADF',
		'beige'      => '#E8DCC8',
		'zandbruin'  => '#C0A183',
		'terracotta' => '#B45C36',
		'bruin'      => '#6F4B34',
		'rood'       => '#B23A2E',
		'oranje'     => '#D2762B',
		'geel'       => '#E0B33A',
		'groen'      => '#4E7A4E',
		'zeegroen'   => '#7A8F7B',
		'blauw'      => '#2E4A7D',
		'kobalt'     => '#2E4A7D',
		'paars'      => '#6B4A7D',
		'roze'       => '#D9A0A8',
		'grijs'      => '#9A9A96',
		'antraciet'  => '#3A342E',
		'zwart'      => '#1E1C1A',
	];
}

/**
 * Term-id => hex, voor elke kleurterm die een geldige hex heeft.
 */
function dp_fcwf_kleur_hexen() {
	$uit      = [];
	$terugval = dp_fcwf_terugval_hexen();

	$termen = get_terms( [
		'taxonomy'   => dp_fcwf_taxonomie(),
		'hide_empty' => false,
	] );

	if ( is_wp_error( $termen ) ) {
		return $uit;
	}

	foreach ( $termen as $term ) {
		$hex = get_term_meta( $term->term_id, 'dp_fc_kleur_hex', true );

		if ( ! $hex ) {
			$hex = $terugval[ $term->slug ] ?? '';
		}

		if ( preg_match( '/^#[0-9a-f]{6}$/i', (string) $hex ) ) {
			$uit[ (int) $term->term_id ] = $hex;
		}
	}

	return apply_filters( 'dp_fc_kleur_hexen', $uit );
}

/**
 * Het filter rendert per kleur een <label class="fct-shop-checkbox"> met daarin
 * een checkbox (value = term-id) en een <span class="checkmark">. Die checkmark
 * maken we tot kleurvlak, zodat je de kleur ziet in plaats van hem te lezen.
 *
 * LANDMIJN: het vinkvakje staat position:absolute op left:0 van het label. Dat
 * MOET zo blijven, anders schuift het staal weg uit de kolom van de andere
 * filters. Hieronder verandert alleen het uiterlijk, niet de positionering.
 *
 * Tweede landmijn: de ring om een aangevinkte kleur gaat naar BINNEN. Een
 * box-shadow met spread naar buiten wordt bij de bovenste kleur afgekapt door de
 * overflow op de filtergroep.
 */
function dp_fcwf_kleurstalen_css() {
	if ( is_admin() ) {
		return;
	}

	$hexen = dp_fcwf_kleur_hexen();

	if ( ! $hexen ) {
		return;
	}

	$taxonomie = dp_fcwf_taxonomie();
	$regels    = [];

	foreach ( $hexen as $term_id => $hex ) {
		$regels[] = sprintf(
			'.fct-shop-checkbox input[name="%1$s"][value="%2$d"] + .checkmark{--dp-fc-swatch:%3$s}',
			esc_attr( $taxonomie ),
			$term_id,
			$hex
		);
	}

	$basis = sprintf(
		'.fct-shop-checkbox input[name="%1$s"] + .checkmark{'
			. 'background:var(--dp-fc-swatch,#e5e5e5);'
			. 'border:1px solid rgba(0,0,0,.25);'
			. 'border-radius:50%%;width:16px;height:16px;'
			. 'transition:box-shadow .15s ease,border-color .15s ease}'
		. '.fct-shop-checkbox input[name="%1$s"]:checked + .checkmark{'
			. 'border-color:currentColor;'
			. 'box-shadow:inset 0 0 0 2px #fff,inset 0 0 0 3px currentColor}'
		. '.fct-shop-checkbox input[name="%1$s"]:checked + .checkmark::after{'
			. 'content:"";position:absolute;inset-inline-start:50%%;inset-block-start:48%%;'
			. 'width:.26rem;height:.5rem;border:solid #fff;border-width:0 2px 2px 0;'
			. 'transform:translate(-50%%,-50%%) rotate(45deg);'
			. 'filter:drop-shadow(0 0 1px rgba(0,0,0,.75))}'
		. '.fct-shop-checkbox input[name="%1$s"]:focus-visible + .checkmark{'
			. 'outline:2px solid currentColor;outline-offset:2px}',
		esc_attr( $taxonomie )
	);

	printf( "<style id=\"dp-fc-kleurstalen\">%s%s</style>\n", $basis, implode( '', $regels ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zelf opgebouwde CSS, waarden zijn gevalideerde hexcodes.
}

/* ---- kleurveld bij de term ---- */

function dp_fcwf_term_veld_nieuw() {
	?>
	<div class="form-field">
		<label for="dp-fc-kleur-hex">Kleur</label>
		<input type="color" id="dp-fc-kleur-hex" name="dp_fc_kleur_hex" value="#cccccc">
		<p>Het bolletje dat bezoekers in het winkelfilter zien.</p>
	</div>
	<?php
	wp_nonce_field( 'dp_fc_kleur_hex', 'dp_fc_kleur_hex_nonce' );
}

function dp_fcwf_term_veld_bewerken( $term ) {
	$hex = get_term_meta( $term->term_id, 'dp_fc_kleur_hex', true );

	if ( ! $hex ) {
		$hex = dp_fcwf_terugval_hexen()[ $term->slug ] ?? '#cccccc';
	}
	?>
	<tr class="form-field">
		<th scope="row"><label for="dp-fc-kleur-hex">Kleur</label></th>
		<td>
			<input type="color" id="dp-fc-kleur-hex" name="dp_fc_kleur_hex" value="<?php echo esc_attr( $hex ); ?>">
			<p class="description">Het bolletje dat bezoekers in het winkelfilter zien.</p>
			<?php wp_nonce_field( 'dp_fc_kleur_hex', 'dp_fc_kleur_hex_nonce' ); ?>
		</td>
	</tr>
	<?php
}

function dp_fcwf_term_veld_opslaan( $term_id ) {
	if ( ! isset( $_POST['dp_fc_kleur_hex_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['dp_fc_kleur_hex_nonce'] ), 'dp_fc_kleur_hex' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	$hex = isset( $_POST['dp_fc_kleur_hex'] ) ? sanitize_hex_color( wp_unslash( $_POST['dp_fc_kleur_hex'] ) ) : '';

	if ( $hex ) {
		update_term_meta( $term_id, 'dp_fc_kleur_hex', $hex );
	} else {
		delete_term_meta( $term_id, 'dp_fc_kleur_hex' );
	}
}

if ( is_admin() ) {
	require_once __DIR__ . '/admin-page.php';
}
