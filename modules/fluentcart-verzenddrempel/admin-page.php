<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', function () {
	register_setting( 'dp_fc_verzenddrempel', 'dp_fc_verzenddrempel_centen', [
		'type'              => 'integer',
		'sanitize_callback' => 'dp_fcvd_sanitize_drempel',
		'default'           => 7500,
	] );

	register_setting( 'dp_fc_verzenddrempel', 'dp_fc_verzenddrempel_balk', [
		'type'              => 'boolean',
		'sanitize_callback' => function ( $v ) {
			return ! empty( $v );
		},
		'default'           => true,
	] );

	foreach ( [ 'onderweg', 'gehaald' ] as $sleutel ) {
		register_setting( 'dp_fc_verzenddrempel', 'dp_fc_verzenddrempel_tekst_' . $sleutel, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
	}
} );

/**
 * Het veld toont euro's, de optie bewaart centen.
 *
 * Bewust allebei de scheidingstekens accepteren: iemand die "75,00" typt bedoelt
 * hetzelfde als iemand die "75.00" typt, en een winkelier hoort niet te moeten
 * raden welke notatie het veld wil.
 */
function dp_fcvd_sanitize_drempel( $invoer ) {
	$tekst = trim( (string) $invoer );

	if ( $tekst === '' ) {
		return 0;
	}

	// Duizendtallen mogen weg, de laatste komma of punt is de decimaal.
	$tekst = preg_replace( '/[^0-9,.]/', '', $tekst );
	$tekst = str_replace( ',', '.', $tekst );

	// Meer dan één punt: alles behalve de laatste was een duizendscheiding.
	$delen = explode( '.', $tekst );
	if ( count( $delen ) > 2 ) {
		$decimalen = array_pop( $delen );
		$tekst     = implode( '', $delen ) . '.' . $decimalen;
	}

	if ( ! is_numeric( $tekst ) ) {
		return (int) get_option( 'dp_fc_verzenddrempel_centen', 7500 );
	}

	return max( 0, (int) round( (float) $tekst * 100 ) );
}

add_action( 'admin_init', function () {
	if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
		dp_toolbox_register_module_settings( 'fluentcart-verzenddrempel', 'dp_fcvd_render_inline', [
			'title'       => 'Verzenddrempel',
			'description' => 'Vanaf welk bedrag de verzending gratis is.',
		] );
	}
} );

function dp_fcvd_render_inline() {
	$centen  = dp_fcvd_drempel();
	$bewaard = (int) get_option( 'dp_fc_verzenddrempel_centen', 7500 );
	$waarde  = number_format( $bewaard / 100, 2, ',', '' );
	?>
	<style>
		.dp-fcvd-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; }
		.dp-fcvd-veld { margin-bottom:22px; }
		.dp-fcvd-veld > strong { display:block; font-size:13px; margin-bottom:8px; color:#1d2327; }
		.dp-fcvd-invoer { display:flex; align-items:center; gap:8px; }
		.dp-fcvd-invoer input { width:120px; }
		.dp-fcvd-hint { font-size:12px; color:#777; margin:6px 0 0; }
		.dp-fcvd-btn { background:#281E5D; color:#fff; border:0; border-radius:6px; padding:8px 24px; font-size:14px; font-weight:600; cursor:pointer; }
		.dp-fcvd-btn:hover { background:#4a3a8a; }
		.dp-fcvd-uitleg { border-top:1px solid #eee; margin-top:24px; padding-top:18px; font-size:13px; color:#555; }
		.dp-fcvd-uitleg p { margin:0 0 8px; }
		.dp-fcvd-code { background:#f4f4f6; border:1px solid #e2e2e6; border-radius:5px; padding:2px 6px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; }
	</style>

	<div class="dp-fcvd-card">
		<form method="post" action="options.php">
			<?php settings_fields( 'dp_fc_verzenddrempel' ); ?>

			<div class="dp-fcvd-veld">
				<strong>Gratis verzending vanaf</strong>
				<div class="dp-fcvd-invoer">
					<label for="dp-fcvd-bedrag">Bedrag</label>
					<input type="text" id="dp-fcvd-bedrag" name="dp_fc_verzenddrempel_centen"
						value="<?php echo esc_attr( $waarde ); ?>" inputmode="decimal">
				</div>
				<p class="dp-fcvd-hint">
					Inclusief btw, in de valuta van je winkel. Alleen fysieke artikelen tellen mee:
					een bestelling met uitsluitend downloads komt nooit aan een verzenddrempel toe.
					Zet het op <code class="dp-fcvd-code">0</code> om de drempel uit te zetten zonder
					de module uit te schakelen.
				</p>
			</div>

			<div class="dp-fcvd-veld">
				<strong>Voortgangsbalk in de winkelwagen</strong>
				<label>
					<?php // Zonder dit verborgen veld stuurt de browser niets mee als het vakje uit staat, en blijft de oude waarde staan. ?>
					<input type="hidden" name="dp_fc_verzenddrempel_balk" value="0">
					<input type="checkbox" name="dp_fc_verzenddrempel_balk" value="1" <?php checked( (bool) get_option( 'dp_fc_verzenddrempel_balk', true ) ); ?>>
					Toon "Nog &hellip; tot gratis verzending" bovenin de winkelwagenlade
				</label>
				<p class="dp-fcvd-hint">
					De balk telt alleen de fysieke artikelen mee — dezelfde berekening als de korting
					zelf, zodat hij nooit iets anders belooft dan de afrekening doet. Staat er niets
					verzendbaars in de winkelwagen, dan blijft de balk weg.
				</p>

				<p style="margin:14px 0 4px;">
					<label for="dp-fcvd-onderweg">Tekst zolang de drempel niet gehaald is</label><br>
					<input type="text" id="dp-fcvd-onderweg" class="regular-text"
						name="dp_fc_verzenddrempel_tekst_onderweg"
						value="<?php echo esc_attr( dp_fcvd_tekst( 'onderweg' ) ); ?>">
				</p>
				<p class="dp-fcvd-hint">
					<code class="dp-fcvd-code">{bedrag}</code> wordt vervangen door wat er nog te gaan is,
					opgemaakt in de valuta van de winkel.
				</p>

				<p style="margin:14px 0 4px;">
					<label for="dp-fcvd-gehaald">Tekst zodra de drempel gehaald is</label><br>
					<input type="text" id="dp-fcvd-gehaald" class="regular-text"
						name="dp_fc_verzenddrempel_tekst_gehaald"
						value="<?php echo esc_attr( dp_fcvd_tekst( 'gehaald' ) ); ?>">
				</p>
			</div>

			<?php submit_button( 'Opslaan', 'primary', 'submit', false, [ 'class' => 'dp-fcvd-btn' ] ); ?>
		</form>

		<div class="dp-fcvd-uitleg">
			<?php if ( $centen > 0 ) : ?>
				<p>
					<strong>Nu actief:</strong> haalt het subtotaal van de fysieke artikelen
					<?php echo esc_html( number_format( $centen / 100, 2, ',', '.' ) ); ?> of meer,
					dan worden de verzendkosten op nul gezet.
				</p>
			<?php else : ?>
				<p><strong>Nu uit:</strong> er wordt geen drempel toegepast.</p>
			<?php endif; ?>
			<p>
				De kosten worden op regelniveau én op cart-niveau genuld. Dat eerste is nodig omdat
				FluentCart de btw over verzending uit de regels afleidt — alleen het totaal nullen laat
				btw staan over verzending die de klant niet betaalt.
			</p>
			<p>
				Wil je de drempel per land of per klantgroep laten verschillen, gebruik dan het filter
				<code class="dp-fcvd-code">dp_fc_verzenddrempel</code>; die krijgt de waarde in centen.
			</p>
		</div>
	</div>
	<?php
}
