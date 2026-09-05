<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', function () {
	register_setting( 'dp_reviews', 'dp_reviews_settings', [
		'type'              => 'array',
		'sanitize_callback' => 'dp_reviews_sanitize_settings',
	] );
} );

function dp_reviews_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : [];

	$toegestaan = wp_list_pluck( dp_reviews_beschikbare_post_types(), 'name' );
	$types      = array_values( array_intersect( (array) ( $input['post_types'] ?? [] ), $toegestaan ) );

	$wie = in_array( $input['who'] ?? '', [ 'everyone', 'logged_in', 'buyers' ], true )
		? $input['who']
		: 'everyone';

	return [
		// Leeg mag: dan staat de module aan maar toont hij nergens iets. Beter
		// dan stilzwijgend een berichttype terugzetten dat de gebruiker net uitzette.
		'post_types'  => $types,
		'who'         => $wie,
		'moderate'    => ! empty( $input['moderate'] ),
		'title_field' => ! empty( $input['title_field'] ),
		'schema'      => ! empty( $input['schema'] ),
	];
}

/**
 * Berichttypes waar reviews op kunnen: alles wat publiek en zichtbaar is.
 */
function dp_reviews_beschikbare_post_types() {
	$types = get_post_types( [ 'public' => true ], 'objects' );
	unset( $types['attachment'] );
	return $types;
}

add_action( 'admin_init', function () {
	if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
		dp_toolbox_register_module_settings( 'reviews', 'dp_reviews_render_inline', [
			'title'       => 'Reviews',
			'description' => 'Beoordelingen met sterren, geverifieerde koop en moderatie.',
		] );
	}
} );

function dp_reviews_render_inline() {
	$s     = dp_reviews_settings();
	$types = dp_reviews_beschikbare_post_types();

	global $wpdb;
	$aantal = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = %s",
		DP_REVIEWS_COMMENT_TYPE
	) );
	$wacht = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = %s AND comment_approved = '0'",
		DP_REVIEWS_COMMENT_TYPE
	) );
	?>
	<style>
		.dp-rvs-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; }
		.dp-rvs-stat { display:flex; gap:20px; margin-bottom:24px; }
		.dp-rvs-box { flex:1; background:#f8f7fc; border-radius:8px; padding:16px; text-align:center; }
		.dp-rvs-num { display:block; font-size:28px; font-weight:700; color:#281E5D; line-height:1; margin-bottom:4px; }
		.dp-rvs-label { font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.5px; }
		.dp-rvs-veld { margin-bottom:22px; }
		.dp-rvs-veld > strong { display:block; font-size:13px; margin-bottom:8px; color:#1d2327; }
		.dp-rvs-veld label { display:block; margin-bottom:6px; font-size:14px; }
		.dp-rvs-hint { font-size:12px; color:#777; margin:4px 0 0; }
		.dp-rvs-btn { background:#281E5D; color:#fff; border:0; border-radius:6px; padding:8px 24px; font-size:14px; font-weight:600; cursor:pointer; }
		.dp-rvs-btn:hover { background:#4a3a8a; }
		.dp-rvs-code { background:#f4f4f6; border:1px solid #e2e2e6; border-radius:5px; padding:2px 6px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; }
		.dp-rvs-uitleg { border-top:1px solid #eee; margin-top:24px; padding-top:18px; font-size:13px; color:#555; }
		.dp-rvs-uitleg p { margin:0 0 8px; }
	</style>

	<div class="dp-rvs-card">
		<div class="dp-rvs-stat">
			<div class="dp-rvs-box">
				<span class="dp-rvs-num"><?php echo esc_html( number_format_i18n( $aantal ) ); ?></span>
				<span class="dp-rvs-label">reviews totaal</span>
			</div>
			<div class="dp-rvs-box">
				<span class="dp-rvs-num"><?php echo esc_html( number_format_i18n( $wacht ) ); ?></span>
				<span class="dp-rvs-label">wacht op goedkeuring</span>
			</div>
		</div>

		<?php if ( $wacht > 0 ) : ?>
			<p><a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>">Naar de moderatiewachtrij</a></p>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'dp_reviews' ); ?>

			<div class="dp-rvs-veld">
				<strong>Reviews toestaan op</strong>
				<?php foreach ( $types as $type ) : ?>
					<label>
						<input type="checkbox" name="dp_reviews_settings[post_types][]" value="<?php echo esc_attr( $type->name ); ?>"
							<?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>>
						<?php echo esc_html( $type->labels->name ); ?>
						<code class="dp-rvs-code"><?php echo esc_html( $type->name ); ?></code>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="dp-rvs-veld">
				<strong>Wie mag een review plaatsen</strong>
				<label><input type="radio" name="dp_reviews_settings[who]" value="everyone" <?php checked( $s['who'], 'everyone' ); ?>> Iedereen, met naam en e-mailadres</label>
				<label><input type="radio" name="dp_reviews_settings[who]" value="logged_in" <?php checked( $s['who'], 'logged_in' ); ?>> Alleen ingelogde gebruikers</label>
				<label><input type="radio" name="dp_reviews_settings[who]" value="buyers" <?php checked( $s['who'], 'buyers' ); ?>> Alleen wie het product aantoonbaar gekocht heeft</label>
				<p class="dp-rvs-hint">
					De laatste optie levert de betrouwbaarste reviews en de minste. Bij de eerste twee krijgt een
					review nog steeds het label &ldquo;geverifieerde koop&rdquo; zodra het e-mailadres bij een
					betaalde bestelling hoort.
				</p>
			</div>

			<div class="dp-rvs-veld">
				<strong>Verwerking</strong>
				<label><input type="checkbox" name="dp_reviews_settings[moderate]" value="1" <?php checked( ! empty( $s['moderate'] ) ); ?>> Eerst goedkeuren voordat een review online komt</label>
				<label><input type="checkbox" name="dp_reviews_settings[title_field]" value="1" <?php checked( ! empty( $s['title_field'] ) ); ?>> Kopveld boven de reviewtekst</label>
				<label><input type="checkbox" name="dp_reviews_settings[schema]" value="1" <?php checked( ! empty( $s['schema'] ) ); ?>> Gestructureerde data (JSON-LD) meesturen</label>
				<p class="dp-rvs-hint">
					Google toont beoordelingssterren voor <em>producten</em> ook als de reviews van je eigen site
					komen. Voor een bedrijfs- of dienstenpagina geldt dat niet: reviews over jezelf worden daar
					genegeerd. Zet dit dus aan op een webshop, niet op een dienstensite.
				</p>
			</div>

			<button type="submit" class="dp-rvs-btn">Opslaan</button>
		</form>

		<div class="dp-rvs-uitleg">
			<p><strong>Weergeven</strong></p>
			<p>Op een Bricks-site staan er twee elementen klaar: <em>Reviews</em> en <em>Reviews samenvatting</em>.</p>
			<p>Overal elders met een shortcode: <code class="dp-rvs-code">[dp_reviews]</code> voor de hele sectie,
				<code class="dp-rvs-code">[dp_reviews_summary]</code> voor de sterrenregel onder de titel.</p>
			<p>Reviews zijn WordPress-reacties met het type <code class="dp-rvs-code">dp_review</code>. Ze staan dus
				gewoon onder Reacties, inclusief moderatie, spamfilter en export.</p>
		</div>
	</div>
	<?php
}
