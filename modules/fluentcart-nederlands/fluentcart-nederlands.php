<?php
/**
 * Module Name: FluentCart Nederlands
 * Description: Vult de ontbrekende Nederlandse teksten van FluentCart aan (btw-specificatie, abonnementsbeheer, zakelijke gegevens) en toont het prijsfilter in de winkel als 12,50 in plaats van 12.5. Springt alleen bij waar de officiële vertaling niets levert, dus een nieuwere .mo wint vanzelf.
 * Category: ecommerce
 * Requires: fluent-cart
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DP_FCNL_VERSION', '1.0.0' );

/* ------------------------------------------------------------------ */
/*  Deel 1 — aanvulling op de vertaling                                */
/* ------------------------------------------------------------------ */

/**
 * De strings die de officiële nl_NL-vertaling (nog) niet levert.
 *
 * Vooral rond btw-specificatie, abonnementsbeheer en zakelijke gegevens: dat
 * zijn de nieuwere schermen van FluentCart, en die lopen achter op de vertaling.
 * Zonder deze aanvulling staat er Engels op de winkelwagen, de afrekenpagina,
 * de bon en het accountgedeelte — precies de plekken waar een klant het ziet.
 *
 * Aan te vullen of te overschrijven met het filter `dp_fc_nl_strings`.
 */
function dp_fcnl_strings() {
	static $map = null;

	if ( $map !== null ) {
		return $map;
	}

	$map = apply_filters( 'dp_fc_nl_strings', [
		// Btw en prijsopbouw.
		'Tax breakdown by rate'                                                                                     => 'Btw-specificatie per tarief',
		'Tax breakdown'                                                                                             => 'Btw-specificatie',
		'Taxable base'                                                                                              => 'Grondslag',
		'Total tax'                                                                                                 => 'Totaal btw',
		'Total payable tax'                                                                                         => 'Totaal te betalen btw',
		'Total tax in this order'                                                                                   => 'Totaal btw in deze bestelling',
		'of which included in prices'                                                                               => 'waarvan inbegrepen in de prijzen',
		'TAX'                                                                                                       => 'BTW',
		'TAX SUMMARY'                                                                                               => 'BTW-OVERZICHT',
		'About your tax'                                                                                            => 'Over de btw',
		'No tax applies to this order.'                                                                             => 'Op deze bestelling is geen btw van toepassing.',
		'Tax has been reversed for this order.'                                                                      => 'De btw is verlegd voor deze bestelling.',
		'"Total payable tax" is added on top of listed prices.'                                                      => '"Totaal te betalen btw" komt boven op de vermelde prijzen.',
		'"Included in item prices" is already built into product prices.'                                            => '"Inbegrepen in de artikelprijzen" zit al in de productprijs verwerkt.',
		'Included in item prices'                                                                                   => 'Inbegrepen in de artikelprijzen',
		'Added on products'                                                                                         => 'Toegevoegd op producten',
		'Included in shipping prices'                                                                               => 'Inbegrepen in de verzendkosten',
		'Added on shipping'                                                                                         => 'Toegevoegd op verzending',
		'Added on fees'                                                                                             => 'Toegevoegd op toeslagen',
		'Payable now (added)'                                                                                       => 'Nu te betalen (toegevoegd)',
		'Tax breakdown explanation'                                                                                 => 'Toelichting btw-specificatie',
		'Inclusive tax is embedded in item prices. Exclusive tax is added on top.'                                   => 'Inclusieve btw zit in de artikelprijs verwerkt. Exclusieve btw komt daar bovenop.',
		'View tax breakdown for this item'                                                                           => 'Bekijk de btw-specificatie van dit artikel',
		'Tax-inclusive price'                                                                                       => 'Prijs inclusief btw',
		'Tax-exclusive price'                                                                                       => 'Prijs exclusief btw',
		'Setup fee tax'                                                                                             => 'Btw over instelkosten',
		'Setup fee tax: %1$s'                                                                                       => 'Btw over instelkosten: %1$s',
		'Tax: %1$s'                                                                                                 => 'Btw: %1$s',
		'Add.'                                                                                                      => 'Toegev.',
		'Reverse charge'                                                                                            => 'Btw verlegd',
		'Charge reversed'                                                                                           => 'Btw verlegd',
		'Tax reversed'                                                                                              => 'Btw verlegd',
		'VAT reversed'                                                                                              => 'Btw verlegd',
		'Tax reversed: %1$s'                                                                                        => 'Btw verlegd: %1$s',
		'Reverse Charge Declaration'                                                                                => 'Verklaring btw-verlegging',
		'Unit price rounding information'                                                                           => 'Informatie over afronding van de stuksprijs',
		'Unit price is rounded for display. The line total is calculated at full precision, so it always reconciles exactly.' => 'De stuksprijs wordt afgerond weergegeven. Het regeltotaal wordt op volle precisie berekend en klopt dus altijd exact.',
		'Unit price is rounded for display. The line total is calculated at full precision.'                          => 'De stuksprijs wordt afgerond weergegeven. Het regeltotaal wordt op volle precisie berekend.',

		// Regels en aantallen.
		'Fee'                                                                                                       => 'Toeslag',
		'fee'                                                                                                       => 'toeslag',
		'Included in %1$s'                                                                                          => 'Inbegrepen in %1$s',
		'Added on %1$s'                                                                                             => 'Toegevoegd op %1$s',
		'Item'                                                                                                      => 'Artikel',
		'Items'                                                                                                     => 'Artikelen',
		'%s items'                                                                                                  => '%s artikelen',
		'Prorate Credit'                                                                                            => 'Verrekend tegoed',
		'Discounted price'                                                                                          => 'Afgeprijsd',
		'%1$s each'                                                                                                 => '%1$s per stuk',
		'Product image thumbnails'                                                                                  => 'Miniaturen van productafbeeldingen',
		'See details'                                                                                               => 'Bekijk details',

		// Zakelijke gegevens.
		'B2B'                                                                                                       => 'Zakelijk',
		'Business Details'                                                                                          => 'Bedrijfsgegevens',
		'I am purchasing as a business'                                                                              => 'Ik koop als bedrijf',
		'Legal Registration ID'                                                                                     => 'KvK-nummer',
		'Legal Registration ID is required.'                                                                         => 'KvK-nummer is verplicht.',
		'Reg. ID'                                                                                                   => 'KvK-nr.',
		'VAT/Tax ID'                                                                                                => 'Btw-nummer',
		'VAT/GST/Tax Number'                                                                                        => 'Btw-nummer',
		'VAT/GST/Tax Number is required.'                                                                            => 'Btw-nummer is verplicht.',
		'Company Name is required.'                                                                                  => 'Bedrijfsnaam is verplicht.',

		// Accountgedeelte en abonnementen.
		'Login'                                                                                                     => 'Inloggen',
		'Dashboard'                                                                                                 => 'Overzicht',
		'Information'                                                                                               => 'Gegevens',
		'Plan'                                                                                                      => 'Abonnement',
		'Upgrade'                                                                                                   => 'Upgraden',
		'View Receipt'                                                                                              => 'Bon bekijken',
		'Pay Now for Invoice'                                                                                       => 'Factuur nu betalen',
		'This order has some due amount. Please complete the payment.'                                               => 'Op deze bestelling staat nog een bedrag open. Rond de betaling af.',
		'This section could not be loaded.'                                                                          => 'Dit onderdeel kon niet worden geladen.',
		'Pause'                                                                                                     => 'Pauzeren',
		'Pause Subscription'                                                                                        => 'Abonnement pauzeren',
		'Pause your subscription? You can resume it anytime.'                                                        => 'Je abonnement pauzeren? Je kunt het op elk moment hervatten.',
		'Resume'                                                                                                    => 'Hervatten',
		'Resume Subscription'                                                                                       => 'Abonnement hervatten',
		'Resume your subscription to continue service.'                                                              => 'Hervat je abonnement om de dienst voort te zetten.',
		'Charged automatically on each renewal date.'                                                                => 'Wordt automatisch afgeschreven op elke verlengingsdatum.',
		'%s Renewal'                                                                                                => '%s verlenging',
		'%s Renewals'                                                                                               => '%s verlengingen',
		'You will be redirected to complete the update.'                                                             => 'Je wordt doorgestuurd om de wijziging af te ronden.',
		'We couldn\'t charge this payment method: %1$s Please update it or pay the open renewal below.'               => 'We konden deze betaalmethode niet belasten: %1$s Werk hem bij of betaal de openstaande verlenging hieronder.',
		'MM/YY'                                                                                                     => 'MM/JJ',
		'PayPal logo'                                                                                               => 'PayPal-logo',
		'None of the available payment methods can process this order. Please contact the store.'                     => 'Geen van de beschikbare betaalmethoden kan deze bestelling verwerken. Neem contact op met de winkel.',
		'Connected License'                                                                                          => 'Gekoppelde licentie',
		'Connected Licenses'                                                                                         => 'Gekoppelde licenties',
		'Manage License'                                                                                             => 'Licentie beheren',
	] );

	return $map;
}

/**
 * Alleen aanvullen waar WordPress de originele string teruggaf — dat betekent
 * dat er géén vertaling was. Levert de .mo later wél iets, dan staat die string
 * hier vanzelf buitenspel en hoeft er niets opgeruimd te worden.
 */
add_filter( 'gettext', function ( $translated, $text, $domain ) {
	if ( $domain !== 'fluent-cart' || $translated !== $text ) {
		return $translated;
	}

	$map = dp_fcnl_strings();

	return $map[ $text ] ?? $translated;
}, 20, 3 );

/**
 * Zelfde principe voor meervoudsvormen.
 */
add_filter( 'ngettext', function ( $translated, $single, $plural, $number, $domain ) {
	if ( $domain !== 'fluent-cart' ) {
		return $translated;
	}

	$origineel = ( (int) $number === 1 ) ? $single : $plural;

	if ( $translated !== $origineel ) {
		return $translated;
	}

	$map = dp_fcnl_strings();

	return $map[ $origineel ] ?? $translated;
}, 20, 5 );

/* ------------------------------------------------------------------ */
/*  Deel 2 — prijsfilter met een komma                                 */
/* ------------------------------------------------------------------ */

/**
 * Het prijsfilter in de winkel rendert twee tekstvelden met een rauwe PHP-float
 * erin ($minPrice / 100), dus je leest "12.5" waar een Nederlandse bezoeker
 * "12,50" verwacht. De schuifregelaar eronder is noUiSlider en die schrijft zijn
 * eigen opmaak in diezelfde velden.
 *
 * Twee helften, en je hebt ze allebei nodig:
 *
 *  1. WEERGAVE — meelezen met de schuifregelaar en de veldwaarde omzetten.
 *  2. SERVER   — diezelfde veldwaarde reist mee terug en belandt in
 *                Helper::toCent(), die begint met is_numeric(). "12,50" is NIET
 *                numeriek, dus zonder de correctie hieronder wordt de prijsgrens
 *                0 en komt er geen enkel product meer terug.
 */

/**
 * "1.234,50" wordt "1234.50"; "12,50" wordt "12.50". Zonder komma niets doen —
 * dan is het al een machinewaarde.
 */
function dp_fcnl_prijs_naar_punt( $waarde ) {
	if ( ! is_string( $waarde ) ) {
		return $waarde;
	}

	$waarde_trim = trim( $waarde );

	if ( strpos( $waarde_trim, ',' ) === false ) {
		return $waarde;
	}

	$genormaliseerd = str_replace( ',', '.', str_replace( '.', '', $waarde_trim ) );

	return is_numeric( $genormaliseerd ) ? $genormaliseerd : $waarde;
}

/**
 * De waarden komen genest binnen als filters[price_range_from] op de REST-route
 * /wp-json/fluent-cart/v2/public/product-views, niet plat op het bovenste niveau
 * van $_GET. Vandaar dat dit recursief zoekt.
 */
function dp_fcnl_prijsfilter_wandel( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	foreach ( $data as $sleutel => $waarde ) {
		if ( is_array( $waarde ) ) {
			$data[ $sleutel ] = dp_fcnl_prijsfilter_wandel( $waarde );
		} elseif ( $sleutel === 'price_range_from' || $sleutel === 'price_range_to' ) {
			$data[ $sleutel ] = dp_fcnl_prijs_naar_punt( $waarde );
		}
	}

	return $data;
}

add_action( 'init', function () {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- alleen normaliseren, geen actie.
	$_GET     = dp_fcnl_prijsfilter_wandel( $_GET );
	$_POST    = dp_fcnl_prijsfilter_wandel( $_POST );
	$_REQUEST = dp_fcnl_prijsfilter_wandel( $_REQUEST );
	// phpcs:enable
}, 1 );

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
<script id="dp-fc-prijsfilter-komma">
(function () {
  function naarKomma(v) {
    var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? v : n.toFixed(2).replace('.', ',');
  }
  function naarPunt(v) {
    var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? v : String(n);
  }

  function bewerk(wrapper) {
    if (!wrapper || !wrapper.noUiSlider || wrapper.dataset.dpFcKomma) return;

    var blok = wrapper.closest('[data-filter-type="range"]');
    if (!blok) return;

    var velden = [
      blok.querySelector('input[data-range-slider-from-value]'),
      blok.querySelector('input[data-range-slider-to-value]')
    ].filter(Boolean);
    if (!velden.length) return;

    wrapper.dataset.dpFcKomma = '1';

    function schrijf() {
      velden.forEach(function (veld) {
        if (document.activeElement === veld) return; // niet ingrijpen tijdens typen
        veld.value = naarKomma(veld.value);
      });
    }
    // Tweede pass in het volgende frame: FluentCart schrijft in zijn eigen
    // update-handler de machinewaarde terug in het veld. Door ook na het frame
    // te herschrijven wint onze opmaak, ongeacht de volgorde binnen de tick.
    function toon() { schrijf(); requestAnimationFrame(schrijf); }

    // LANDMIJN: NIET koppelen aan 'update'. noUiSlider zendt dat event uit op
    // het moment dat je eraan koppelt (eenmaal per handgreep), en FluentCart
    // telt update-events om te bepalen of de bezoeker heeft gefilterd. Eentje
    // extra bij het laden en de winkel haalt zichzelf opnieuw op -- zichtbaar
    // als een flits van producten, dan de laadindicator, dan weer producten.
    // 'slide' en 'set' vuren niet bij het koppelen en dekken samen alles:
    // slepen, loslaten, .set() en de reset-knop.
    wrapper.noUiSlider.on('slide', toon);
    wrapper.noUiSlider.on('set', toon);

    // Bij het typen even terug naar de machinewaarde, zodat er geen
    // dubbelzinnigheid ontstaat over punt of komma.
    velden.forEach(function (veld) {
      veld.addEventListener('focus', function () { veld.value = naarPunt(veld.value); });
      veld.addEventListener('blur', function () { veld.value = naarKomma(veld.value); });
    });

    toon();
  }

  function scan() {
    document.querySelectorAll('[data-range-slider-wrapper]').forEach(bewerk);
  }

  var kijker = new MutationObserver(scan);

  // De schuifregelaar wordt door de shop-app aangemaakt, dus even volhouden in
  // plaats van eenmalig kijken. Staat er na ~4 seconden nog steeds geen
  // prijsfilter, dan is dit geen winkelpagina en stoppen we ook met kijken --
  // anders blijft er op elke pagina van de site een observer meelopen.
  var pogingen = 0;
  var timer = setInterval(function () {
    scan();
    if (++pogingen > 60) {
      clearInterval(timer);
      if (!document.querySelector('[data-range-slider-wrapper]')) {
        kijker.disconnect();
      }
    }
  }, 60);

  kijker.observe(document.body, { childList: true, subtree: true });
})();
</script>
	<?php
}, 30 );
