<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', function () {
	register_setting( 'dp_fc_winkelfixes', 'dp_fc_winkelfixes', [
		'type'              => 'array',
		'sanitize_callback' => 'dp_fcwf_sanitize',
	] );
} );

/**
 * Elke sleutel expliciet wegschrijven.
 *
 * Een niet-aangevinkt vakje stuurt de browser niet mee, dus zonder deze
 * expliciete opbouw zou een uitgezet onderdeel bij het volgende uitlezen weer op
 * zijn standaard terugvallen — en het kleurfilter zou zichzelf nooit laten
 * uitzetten.
 */
function dp_fcwf_sanitize( $invoer ) {
	$invoer = is_array( $invoer ) ? $invoer : [];
	$uit    = [];

	foreach ( array_keys( dp_fcwf_standaarden() ) as $onderdeel ) {
		$uit[ $onderdeel ] = ! empty( $invoer[ $onderdeel ] );
	}

	return $uit;
}

add_action( 'admin_init', function () {
	if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
		dp_toolbox_register_module_settings( 'fluentcart-winkelfixes', 'dp_fcwf_render_inline', [
			'title'       => 'Winkelfixes',
			'description' => 'Welke van de drie verbeteringen actief zijn.',
		] );
	}
} );

function dp_fcwf_render_inline() {
	$s = dp_fcwf_instellingen();

	// Zonder tussenformaten valt er voor de browser niets te kiezen: srcset wordt
	// dan leeg en dit onderdeel doet stilletjes niets. De bekende oorzaak is de
	// module WebP Converter, die standaard alles behalve de thumbnail uitzet.
	//
	// Bewust niet op get_intermediate_image_sizes() toetsen: die geeft de
	// GEREGISTREERDE formaten terug, en dat lijstje verandert niet door het filter
	// dat de formaten daadwerkelijk tegenhoudt. De waarschuwing zou dan nooit
	// verschijnen.
	$webp_beperkt = function_exists( 'dp_toolbox_webp_sizes_mode' )
		&& dp_toolbox_webp_sizes_mode() === 'thumbnail';

	$taxonomie = dp_fcwf_taxonomie();
	$aantal    = 0;

	if ( taxonomy_exists( $taxonomie ) ) {
		$termen = get_terms( [ 'taxonomy' => $taxonomie, 'hide_empty' => false, 'fields' => 'ids' ] );
		$aantal = is_wp_error( $termen ) ? 0 : count( $termen );
	}
	?>
	<style>
		.dp-fcwf-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; }
		.dp-fcwf-fix { border:1px solid #eee; border-radius:6px; padding:14px 16px; margin-bottom:14px; }
		.dp-fcwf-fix > label { display:block; font-size:14px; font-weight:600; margin-bottom:6px; }
		.dp-fcwf-fix p { margin:0 0 6px; font-size:13px; color:#555; }
		.dp-fcwf-fix p:last-child { margin-bottom:0; }
		.dp-fcwf-let { background:#fdf6e3; border:1px solid #e8d9a8; border-radius:5px; padding:8px 12px; font-size:12px; color:#6b5a1e; margin-top:8px; }
		.dp-fcwf-ok { font-size:12px; color:#2b6a3f; margin-top:8px; }
		.dp-fcwf-btn { background:#281E5D; color:#fff; border:0; border-radius:6px; padding:8px 24px; font-size:14px; font-weight:600; cursor:pointer; }
		.dp-fcwf-btn:hover { background:#4a3a8a; }
		.dp-fcwf-code { background:#f4f4f6; border:1px solid #e2e2e6; border-radius:5px; padding:2px 6px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; }
	</style>

	<div class="dp-fcwf-card">
		<form method="post" action="options.php">
			<?php settings_fields( 'dp_fc_winkelfixes' ); ?>

			<div class="dp-fcwf-fix">
				<label>
					<input type="checkbox" name="dp_fc_winkelfixes[variantfoto]" value="1" <?php checked( ! empty( $s['variantfoto'] ) ); ?>>
					Productfoto volgt de gekozen variant
				</label>
				<p>
					Kiest een bezoeker een kleur, dan wisselt de hoofdfoto mee en licht de bijbehorende
					miniatuur op. Nodig zodra je de productpagina zelf bouwt met de galerij en het
					koopblok als losse elementen — FluentCarts eigen galerij luistert dan niet meer mee,
					zonder foutmelding.
				</p>
			</div>

			<div class="dp-fcwf-fix">
				<label>
					<input type="checkbox" name="dp_fc_winkelfixes[srcset]" value="1" <?php checked( ! empty( $s['srcset'] ) ); ?>>
					srcset op productkaarten
				</label>
				<p>
					FluentCart rendert de kaartafbeelding zonder srcset, waardoor een kaartje van 300px
					het volledige origineel binnenhaalt. Hiermee kiest de browser zelf een passende maat.
					Breedtes aanpassen kan met het filter <code class="dp-fcwf-code">dp_fc_kaart_sizes</code>.
				</p>
				<?php if ( $webp_beperkt ) : ?>
					<div class="dp-fcwf-let">
						Let op: de module <strong>WebP Converter</strong> staat op <em>Alleen thumbnail</em>,
						dus WordPress maakt geen tussenformaten aan en er valt voor de browser niets te
						kiezen — dit onderdeel doet dan niets. Zet hem daar op
						<em>Thumbnail + medium, medium_large en large</em>.
					</div>
				<?php endif; ?>
			</div>

			<div class="dp-fcwf-fix">
				<label>
					<input type="checkbox" name="dp_fc_winkelfixes[kleurfilter]" value="1" <?php checked( ! empty( $s['kleurfilter'] ) ); ?>>
					Kleur als winkelfilter, met kleurstalen
				</label>
				<p>
					Voegt de taxonomie <code class="dp-fcwf-code"><?php echo esc_html( $taxonomie ); ?></code>
					toe aan je producten en toont de kleuren in het winkelfilter als bolletjes in plaats van
					als tekst. Per kleur zet je de hex op het termscherm; voor gangbare Nederlandse
					kleurnamen zit er een terugval in.
				</p>
				<p>
					FluentCarts ingebouwde Color-attribuutgroep is voor <em>varianten</em> en verschijnt
					niet in de filters — dit staat daar los van.
				</p>
				<?php if ( ! empty( $s['kleurfilter'] ) && taxonomy_exists( $taxonomie ) ) : ?>
					<p class="dp-fcwf-ok">
						<?php echo esc_html( number_format_i18n( $aantal ) ); ?> kleur<?php echo $aantal === 1 ? '' : 'en'; ?> vastgelegd —
						<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $taxonomie ) . '&post_type=fluent-products' ) ); ?>">kleuren beheren</a>
					</p>
				<?php endif; ?>
			</div>

			<?php submit_button( 'Opslaan', 'primary', 'submit', false, [ 'class' => 'dp-fcwf-btn' ] ); ?>
		</form>
	</div>
	<?php
}
