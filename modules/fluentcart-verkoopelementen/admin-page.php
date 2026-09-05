<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', function () {
	register_setting( 'dp_fc_verkoopelementen', 'dp_fc_verkoopelementen', [
		'type'              => 'array',
		'sanitize_callback' => 'dp_fcve_sanitize',
	] );

	$tekstvelden = [
		'dp_fc_badges_aanbieding_label',
		'dp_fc_badges_voorraad_label',
		'dp_fc_badges_laatste_label',
		'dp_fc_bezorgtekst',
		'dp_fc_bezorgtekst_digitaal',
	];

	foreach ( $tekstvelden as $veld ) {
		register_setting( 'dp_fc_verkoopelementen', $veld, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
	}

	register_setting( 'dp_fc_verkoopelementen', 'dp_fc_badges_voorraaddrempel', [
		'type'              => 'integer',
		'sanitize_callback' => function ( $v ) {
			return max( 0, (int) $v );
		},
		'default'           => 5,
	] );

	foreach ( [ 'dp_fc_badges_categorieen', 'dp_fc_usp', 'dp_fc_usp_digitaal' ] as $veld ) {
		register_setting( 'dp_fc_verkoopelementen', $veld, [
			'type'              => 'string',
			'sanitize_callback' => 'dp_fcve_sanitize_regels',
		] );
	}
} );

/**
 * Elke sleutel expliciet wegschrijven — een niet-aangevinkt vakje stuurt de
 * browser niet mee, en zou anders bij het volgende uitlezen weer op zijn
 * standaard terugvallen.
 */
function dp_fcve_sanitize( $invoer ) {
	$invoer = is_array( $invoer ) ? $invoer : [];
	$uit    = [];

	foreach ( array_keys( dp_fcve_standaarden() ) as $onderdeel ) {
		$uit[ $onderdeel ] = ! empty( $invoer[ $onderdeel ] );
	}

	return $uit;
}

/**
 * Meerregelige velden: per regel saneren, lege regels eruit. `wp_kses_post` en
 * niet `sanitize_text_field`, want in een koopvoordeel mag een <strong> staan.
 */
function dp_fcve_sanitize_regels( $invoer ) {
	$regels = preg_split( '/\R/', (string) $invoer );
	$schoon = [];

	foreach ( $regels as $regel ) {
		$regel = trim( wp_kses_post( $regel ) );

		if ( $regel !== '' ) {
			$schoon[] = $regel;
		}
	}

	return implode( "\n", $schoon );
}

add_action( 'admin_init', function () {
	if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
		dp_toolbox_register_module_settings( 'fluentcart-verkoopelementen', 'dp_fcve_render_inline', [
			'title'       => 'Verkoopelementen',
			'description' => 'Welke onderdelen actief zijn, en de teksten erin.',
		] );
	}
} );

function dp_fcve_render_inline() {
	$s = dp_fcve_instellingen();

	$usp_standaard = "Veilig betalen met iDEAL, creditcard of Bancontact\nGratis verzending vanaf &euro;75 binnen Nederland\nZorgvuldig verpakt, breuk onderweg vergoeden wij\n30 dagen bedenktijd, retour op onze kosten";
	$usp_digitaal  = "Veilig betalen met iDEAL, creditcard of Bancontact\nDirect beschikbaar, ook later opnieuw te downloaden";
	?>
	<style>
		.dp-fcve-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; }
		.dp-fcve-blok { border:1px solid #eee; border-radius:6px; padding:14px 16px; margin-bottom:14px; }
		.dp-fcve-blok > label.dp-fcve-kop { display:block; font-size:14px; font-weight:600; margin-bottom:8px; }
		.dp-fcve-blok p { margin:0 0 8px; font-size:13px; color:#555; }
		.dp-fcve-rij { margin:10px 0 0; }
		.dp-fcve-rij label { display:block; font-size:13px; font-weight:600; margin-bottom:4px; color:#1d2327; }
		.dp-fcve-rij input[type="text"] { width:100%; max-width:520px; }
		.dp-fcve-rij input[type="number"] { width:90px; }
		.dp-fcve-rij textarea { width:100%; max-width:520px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; }
		.dp-fcve-hint { font-size:12px; color:#777; margin:4px 0 0; }
		.dp-fcve-btn { background:#281E5D; color:#fff; border:0; border-radius:6px; padding:8px 24px; font-size:14px; font-weight:600; cursor:pointer; }
		.dp-fcve-btn:hover { background:#4a3a8a; }
		.dp-fcve-code { background:#f4f4f6; border:1px solid #e2e2e6; border-radius:5px; padding:2px 6px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; }
	</style>

	<div class="dp-fcve-card">
		<form method="post" action="options.php">
			<?php settings_fields( 'dp_fc_verkoopelementen' ); ?>

			<div class="dp-fcve-blok">
				<label class="dp-fcve-kop">
					<input type="checkbox" name="dp_fc_verkoopelementen[badges]" value="1" <?php checked( ! empty( $s['badges'] ) ); ?>>
					Badges op productkaarten
				</label>
				<p>
					Alles wordt uit de data afgeleid, nooit uit een lijst met productnamen. De
					aanbiedingsbadge gebruikt exact dezelfde voorwaarde als FluentCart voor de
					doorgestreepte prijs, zodat badge en prijs elkaar nooit tegenspreken. Maximaal drie
					badges per kaart.
				</p>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-aanbieding">Tekst van de aanbiedingsbadge</label>
					<input type="text" id="dp-fcve-aanbieding" name="dp_fc_badges_aanbieding_label"
						value="<?php echo esc_attr( get_option( 'dp_fc_badges_aanbieding_label', 'Aanbieding' ) ); ?>">
				</div>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-categorieen">Badges uit categorieën</label>
					<textarea id="dp-fcve-categorieen" name="dp_fc_badges_categorieen" rows="3"
						placeholder="nieuw: Nieuw&#10;kleine-oplage: Kleine oplage"><?php
						echo esc_textarea( get_option( 'dp_fc_badges_categorieen', "nieuw: Nieuw\nkleine-oplage: Kleine oplage" ) );
					?></textarea>
					<p class="dp-fcve-hint">
						Een per regel, als <code class="dp-fcve-code">slug: Label</code>. Zit het product in
						die productcategorie, dan krijgt de kaart die badge.
					</p>
				</div>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-drempel">Voorraadbadge vanaf</label>
					<input type="number" id="dp-fcve-drempel" name="dp_fc_badges_voorraaddrempel" min="0" step="1"
						value="<?php echo esc_attr( (int) get_option( 'dp_fc_badges_voorraaddrempel', 5 ) ); ?>">
					<p class="dp-fcve-hint">
						Toont de resterende voorraad zodra die op of onder dit aantal komt. Alleen bij
						producten waar de voorraad daadwerkelijk bijgehouden wordt. Op
						<code class="dp-fcve-code">0</code> zetten om deze badge uit te schakelen.
					</p>
				</div>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-voorraadtekst">Tekst voorraadbadge</label>
					<input type="text" id="dp-fcve-voorraadtekst" name="dp_fc_badges_voorraad_label"
						value="<?php echo esc_attr( get_option( 'dp_fc_badges_voorraad_label', 'Nog %d op voorraad' ) ); ?>">
					<p class="dp-fcve-hint"><code class="dp-fcve-code">%d</code> wordt het aantal.</p>
				</div>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-laatste">Tekst bij nog één stuk</label>
					<input type="text" id="dp-fcve-laatste" name="dp_fc_badges_laatste_label"
						value="<?php echo esc_attr( get_option( 'dp_fc_badges_laatste_label', 'Laatste exemplaar' ) ); ?>">
				</div>
			</div>

			<div class="dp-fcve-blok">
				<label class="dp-fcve-kop">
					<input type="checkbox" name="dp_fc_verkoopelementen[voorraadlabel]" value="1" <?php checked( ! empty( $s['voorraadlabel'] ) ); ?>>
					Voorraadlabel onder de prijs
				</label>
				<p>
					FluentCart zet zijn voorraadlabel onder de titel en schrijft er "Beschikbaarheid:" voor.
					Onder de prijs is het logischer — daar kijkt de klant op het moment dat hij besluit.
					Alleen van toepassing op FluentCarts eigen productsjabloon; bouw je de pagina zelf, dan
					zet je het voorraad-element gewoon waar je wilt.
				</p>
			</div>

			<div class="dp-fcve-blok">
				<label class="dp-fcve-kop">
					<input type="checkbox" name="dp_fc_verkoopelementen[bezorging]" value="1" <?php checked( ! empty( $s['bezorging'] ) ); ?>>
					Bezorgbelofte boven de koopknop
				</label>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-bezorg">Bij fysieke producten</label>
					<input type="text" id="dp-fcve-bezorg" name="dp_fc_bezorgtekst"
						value="<?php echo esc_attr( get_option( 'dp_fc_bezorgtekst', 'Voor 17.00 uur besteld, de volgende werkdag verzonden' ) ); ?>">
				</div>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-bezorg-dig">Bij downloads</label>
					<input type="text" id="dp-fcve-bezorg-dig" name="dp_fc_bezorgtekst_digitaal"
						value="<?php echo esc_attr( get_option( 'dp_fc_bezorgtekst_digitaal', 'Direct na betaling te downloaden' ) ); ?>">
					<p class="dp-fcve-hint">Leeg laten om de regel bij dat soort producten weg te laten.</p>
				</div>
			</div>

			<div class="dp-fcve-blok">
				<label class="dp-fcve-kop">
					<input type="checkbox" name="dp_fc_verkoopelementen[usp]" value="1" <?php checked( ! empty( $s['usp'] ) ); ?>>
					Koopvoordelen onder de koopknop
				</label>
				<p>
					Een vinkje per regel. Beloof hier alleen wat de winkel waarmaakt — een regel over iDEAL
					terwijl er alleen een testbetaling aanstaat valt onmiddellijk door de mand.
				</p>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-usp">Bij fysieke producten</label>
					<textarea id="dp-fcve-usp" name="dp_fc_usp" rows="5"
						placeholder="<?php echo esc_attr( $usp_standaard ); ?>"><?php
						echo esc_textarea( get_option( 'dp_fc_usp', '' ) );
					?></textarea>
				</div>

				<div class="dp-fcve-rij">
					<label for="dp-fcve-usp-dig">Bij downloads</label>
					<textarea id="dp-fcve-usp-dig" name="dp_fc_usp_digitaal" rows="4"
						placeholder="<?php echo esc_attr( $usp_digitaal ); ?>"><?php
						echo esc_textarea( get_option( 'dp_fc_usp_digitaal', '' ) );
					?></textarea>
					<p class="dp-fcve-hint">
						Een e-book heeft niets aan "gratis verzending", vandaar twee lijsten. Allebei leeg
						laten betekent: geen lijst.
					</p>
				</div>
			</div>

			<?php submit_button( 'Opslaan', 'primary', 'submit', false, [ 'class' => 'dp-fcve-btn' ] ); ?>
		</form>
	</div>
	<?php
}
