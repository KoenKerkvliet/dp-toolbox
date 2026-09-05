<?php
/**
 * Module Name: FluentCart Verzenddrempel
 * Description: Gratis verzending zodra het subtotaal van de fysieke artikelen een bedrag haalt. FluentCart rekent per zone, gewicht of orderbedrag maar kent geen drempel op ordertotaal; deze module vult dat aan, inclusief de btw over verzending. Optioneel met een voortgangsbalk in de winkelwagen: "Nog €12,50 tot gratis verzending".
 * Category: ecommerce
 * Requires: fluent-cart
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DP_FCVD_VERSION', '1.0.0' );

/**
 * De drempel in centen, inclusief btw.
 *
 * Nul (of lager) betekent: geen drempel. Zo kun je de module aan laten staan en
 * de gratis verzending toch uitzetten zonder instellingen kwijt te raken.
 * Te overschrijven met het filter `dp_fc_verzenddrempel`, bijvoorbeeld om per
 * land of per klantgroep een andere grens te hanteren.
 */
function dp_fcvd_drempel() {
	$centen = (int) get_option( 'dp_fc_verzenddrempel_centen', 7500 );

	return (int) apply_filters( 'dp_fc_verzenddrempel', $centen );
}

/**
 * Subtotaal van de fysieke regels in centen, na regelkorting.
 *
 * Digitale producten tellen niet mee — die worden niet verzonden, en een winkel
 * die een e-book van 80 euro verkoopt hoort daar geen gratis verzending op te
 * geven voor de mok die er los bij zit.
 */
function dp_fcvd_fysiek_subtotaal( $cart ) {
	$totaal = 0;

	foreach ( (array) ( $cart->cart_data ?? [] ) as $regel ) {
		if ( ( $regel['fulfillment_type'] ?? '' ) !== 'physical' ) {
			continue;
		}
		$totaal += (int) ( $regel['line_total'] ?? 0 );
	}

	return $totaal;
}

function dp_fcvd_komt_in_aanmerking( $cart ) {
	$drempel = dp_fcvd_drempel();

	if ( $drempel <= 0 ) {
		return false;
	}

	if ( ! $cart || empty( $cart->cart_data ) ) {
		return false;
	}

	return dp_fcvd_fysiek_subtotaal( $cart ) >= $drempel;
}

/**
 * Nul de verzendkosten zodra de drempel gehaald is.
 *
 * Deze actie vuurt direct nadat FluentCart de kosten heeft berekend en de cart
 * heeft opgeslagen. De statische vlag voorkomt dat onze eigen save() de actie
 * opnieuw triggert.
 *
 * Op regelniveau én op cart-niveau, en dat eerste is niet optioneel: de btw over
 * verzending wordt afgeleid uit `shipping_charge` en `itemwise_shipping_charge`
 * van de regels, niet uit het totaal. Alleen het totaal nullen laat btw staan
 * over verzending die de klant niet betaalt — een boekhoudkundig verschil dat
 * pas bij de aangifte opvalt.
 */
add_action( 'fluent_cart/checkout/shipping_data_changed', function ( $data ) {
	static $bezig = false;

	if ( $bezig ) {
		return;
	}

	$cart = is_array( $data ) ? ( $data['cart'] ?? null ) : null;

	if ( ! $cart || ! dp_fcvd_komt_in_aanmerking( $cart ) ) {
		return;
	}

	$regels   = (array) $cart->cart_data;
	$checkout = (array) $cart->checkout_data;

	$huidig_totaal = (int) ( $checkout['shipping_data']['shipping_charge'] ?? 0 );
	$regelkosten   = false;

	foreach ( $regels as $regel ) {
		if ( (int) ( $regel['shipping_charge'] ?? 0 ) || (int) ( $regel['itemwise_shipping_charge'] ?? 0 ) ) {
			$regelkosten = true;
			break;
		}
	}

	// Al gratis: geen overbodige save.
	if ( ! $huidig_totaal && ! $regelkosten ) {
		return;
	}

	foreach ( $regels as $sleutel => $regel ) {
		$regels[ $sleutel ]['shipping_charge']          = 0;
		$regels[ $sleutel ]['itemwise_shipping_charge'] = 0;
	}

	$checkout['shipping_data']['shipping_charge'] = 0;

	$bezig               = true;
	$cart->cart_data     = $regels;
	$cart->checkout_data = $checkout;
	$cart->save();
	$bezig               = false;
}, 20 );

/**
 * Vangnet voor de weergave: mocht een codepad het opgeslagen bedrag tonen zonder
 * herberekening, dan blijft het totaal alsnog nul.
 */
add_filter( 'fluent_cart/cart/shipping_total', function ( $totaal, $data ) {
	$cart = is_array( $data ) ? ( $data['cart'] ?? null ) : null;

	return dp_fcvd_komt_in_aanmerking( $cart ) ? 0 : $totaal;
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  Voortgangsbalk in de winkelwagen                                    */
/* ------------------------------------------------------------------ */

function dp_fcvd_balk_aan() {
	return (bool) get_option( 'dp_fc_verzenddrempel_balk', true ) && dp_fcvd_drempel() > 0;
}

function dp_fcvd_tekst( $sleutel ) {
	$standaarden = [
		'onderweg' => 'Nog {bedrag} tot gratis verzending',
		'gehaald'  => 'Je bestelling wordt gratis verzonden',
	];

	$tekst = (string) get_option( 'dp_fc_verzenddrempel_tekst_' . $sleutel, $standaarden[ $sleutel ] ?? '' );

	return $tekst !== '' ? $tekst : ( $standaarden[ $sleutel ] ?? '' );
}

/**
 * De huidige stand, in de vorm waarin zowel PHP als JS hem kan gebruiken.
 *
 * Bewust dezelfde functies als de korting zelf (`dp_fcvd_fysiek_subtotaal`,
 * `dp_fcvd_drempel`), zodat de balk nooit iets anders belooft dan de afrekening
 * doet. Dat is ook de reden dat de JS deze waarden ophaalt in plaats van ze uit
 * de zichtbare cart-totalen te herleiden: die tellen digitale artikelen mee.
 */
function dp_fcvd_balk_data() {
	$drempel = dp_fcvd_drempel();

	$leeg = [
		'toon'       => false,
		'gehaald'    => false,
		'percentage' => 0,
		'tekst'      => '',
	];

	if ( $drempel <= 0 || ! class_exists( '\FluentCart\App\Helpers\CartHelper' ) ) {
		return $leeg;
	}

	$cart = \FluentCart\App\Helpers\CartHelper::getCart( null, false );

	if ( ! $cart || empty( $cart->cart_data ) ) {
		return $leeg;
	}

	$subtotaal = dp_fcvd_fysiek_subtotaal( $cart );

	// Alleen downloads in de winkelwagen: er valt niets te verdienen, dus ook
	// niets te tonen. Een balk die nooit vol kan lopen is een valse belofte.
	if ( $subtotaal < 1 ) {
		return $leeg;
	}

	$gehaald   = $subtotaal >= $drempel;
	$resterend = max( 0, $drempel - $subtotaal );

	if ( $gehaald ) {
		$tekst = dp_fcvd_tekst( 'gehaald' );
	} else {
		$bedrag = class_exists( '\FluentCart\App\Helpers\Helper' )
			? \FluentCart\App\Helpers\Helper::toDecimal( $resterend )
			: number_format_i18n( $resterend / 100, 2 );

		$tekst = str_replace( '{bedrag}', $bedrag, dp_fcvd_tekst( 'onderweg' ) );
	}

	return [
		'toon'       => true,
		'gehaald'    => $gehaald,
		'percentage' => (int) min( 100, round( $subtotaal / $drempel * 100 ) ),
		// Entiteiten decoderen: Helper::toDecimal() geeft het valutateken terug als
		// `&euro;`, en de JS zet de tekst met textContent (terecht — dat kan geen
		// HTML injecteren). Zonder deze regel leest de bezoeker letterlijk "&euro;".
		'tekst'      => wp_strip_all_tags( html_entity_decode( $tekst, ENT_QUOTES, 'UTF-8' ) ),
	];
}

/**
 * De stand als losse route.
 *
 * Alleen lezen, en alleen over de winkelwagen van de bezoeker zelf — dezelfde
 * gegevens die hij een regel lager op zijn scherm ziet staan. Vandaar geen
 * rechtencontrole; een nonce zou hier alleen maar breken op paginacache.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'dp-toolbox/v1', '/verzenddrempel', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			return rest_ensure_response( dp_fcvd_balk_data() );
		},
	] );
} );

if ( dp_fcvd_balk_aan() ) {
	/**
	 * FluentCart rendert de lade zelf op `wp_footer` (CartLoader::init) en biedt
	 * daarbinnen geen enkele actie om iets tussen te schuiven — de lade bestaat
	 * bovendien niet zolang de winkelwagen leeg is. Daarom zetten we de balk in
	 * de voettekst neer en verhuist de JS hem naar de kop van de lade zodra die
	 * er is, en anders bij de eerstvolgende wijziging.
	 */
	add_action( 'wp_footer', function () {
		if ( is_admin() ) {
			return;
		}

		$data = dp_fcvd_balk_data();
		?>
<div class="dp-fc-vd" data-dp-fc-verzenddrempel data-geplaatst="nee"<?php echo $data['toon'] ? '' : ' hidden'; ?>>
	<p class="dp-fc-vd__tekst" data-dp-fc-verzenddrempel-tekst><?php echo esc_html( $data['tekst'] ); ?></p>
	<div class="dp-fc-vd__spoor">
		<span class="dp-fc-vd__vol" data-dp-fc-verzenddrempel-vol style="width:<?php echo (int) $data['percentage']; ?>%"></span>
	</div>
</div>
<style id="dp-fc-vd-css">
.dp-fc-vd { padding: .9rem 1.25rem; font-size: .875rem; line-height: 1.4; }
/* Zolang de balk nog in de voettekst staat en niet in de lade, hoort hij weg te
   blijven — anders staat er onderaan elke pagina een losse balk. Op de positie
   in de DOM toetsen kan niet: waar wp_footer uitkomt verschilt per thema. */
.dp-fc-vd:not([data-geplaatst="ja"]) { display: none; }
.dp-fc-vd__tekst { margin: 0 0 .5rem; }
.dp-fc-vd__spoor {
	height: 6px;
	border-radius: 999px;
	background: rgba(0,0,0,.1);
	overflow: hidden;
}
.dp-fc-vd__vol {
	display: block;
	height: 100%;
	border-radius: inherit;
	background: currentColor;
	transition: width .35s ease;
}
.dp-fc-vd.is-gehaald .dp-fc-vd__tekst { font-weight: 600; }
@media (prefers-reduced-motion: reduce) {
	.dp-fc-vd__vol { transition: none; }
}
</style>
<script id="dp-fc-vd-js">
(function () {
	"use strict";

	var balk = document.querySelector("[data-dp-fc-verzenddrempel]");
	if (!balk) { return; }

	var route = <?php echo wp_json_encode( esc_url_raw( rest_url( 'dp-toolbox/v1/verzenddrempel' ) ) ); ?>;

	function plaats() {
		// De lade wordt pas gerenderd zodra er iets in de winkelwagen zit, dus
		// bij elke wijziging opnieuw proberen tot het lukt.
		var kop = document.querySelector(".fct-cart-drawer .fct-cart-drawer-header");
		if (!kop || kop.nextElementSibling === balk) { return; }
		kop.parentNode.insertBefore(balk, kop.nextSibling);
		balk.setAttribute("data-geplaatst", "ja");
	}

	function teken(data) {
		balk.hidden = !data || !data.toon;
		if (balk.hidden) { return; }

		balk.classList.toggle("is-gehaald", !!data.gehaald);
		balk.querySelector("[data-dp-fc-verzenddrempel-tekst]").textContent = data.tekst;
		balk.querySelector("[data-dp-fc-verzenddrempel-vol]").style.width = data.percentage + "%";
	}

	function ververs() {
		plaats();
		fetch(route, { credentials: "same-origin" })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(teken)
			.catch(function () { /* stil: een balk die niet bijwerkt is geen storing */ });
	}

	plaats();

	// FluentCart stuurt dit event bij elke wijziging van de winkelwagen:
	// toevoegen, verwijderen en aantal aanpassen.
	window.addEventListener("fluentCartNotifyCartDrawerItemChanged", ververs);
}());
</script>
		<?php
	}, 20 );
}

if ( is_admin() ) {
	require_once __DIR__ . '/admin-page.php';
}
