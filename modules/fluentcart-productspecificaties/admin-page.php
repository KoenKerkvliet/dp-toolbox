<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', function () {
	register_setting( 'dp_fc_specs', 'dp_fc_specs_titel', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'Specificaties',
	] );

	register_setting( 'dp_fc_specs', 'dp_fc_specs_layout', [
		'type'              => 'string',
		'sanitize_callback' => function ( $v ) {
			return $v === 'onder' ? 'onder' : 'naast';
		},
		'default'           => 'naast',
	] );

	register_setting( 'dp_fc_specs', 'dp_fc_specs_menu', [
		'type'              => 'boolean',
		'sanitize_callback' => function ( $v ) {
			return ! empty( $v );
		},
		'default'           => true,
	] );
} );

add_action( 'admin_init', function () {
	if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
		dp_toolbox_register_module_settings( 'fluentcart-productspecificaties', 'dp_fcps_render_inline', [
			'title'       => 'Productspecificaties',
			'description' => 'Kop, plaatsing en waar je de specificaties invult.',
		] );
	}
} );

function dp_fcps_render_inline() {
	global $wpdb;

	$gevuld = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
		DP_FCPS_META
	) );

	$producten = (int) wp_count_posts( 'fluent-products' )->publish;
	$layout    = dp_fcps_layout();
	$bricks    = function_exists( 'dp_toolbox_bricks_is_available' ) && dp_toolbox_bricks_is_available();
	?>
	<style>
		.dp-fcps-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; }
		.dp-fcps-stat { display:flex; gap:20px; margin-bottom:24px; }
		.dp-fcps-box { flex:1; background:#f8f7fc; border-radius:8px; padding:16px; text-align:center; }
		.dp-fcps-num { display:block; font-size:28px; font-weight:700; color:#281E5D; line-height:1; margin-bottom:4px; }
		.dp-fcps-label { font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.5px; }
		.dp-fcps-veld { margin-bottom:22px; }
		.dp-fcps-veld > strong { display:block; font-size:13px; margin-bottom:8px; color:#1d2327; }
		.dp-fcps-veld label { display:block; margin-bottom:6px; font-size:14px; }
		.dp-fcps-hint { font-size:12px; color:#777; margin:4px 0 0; }
		.dp-fcps-btn { background:#281E5D; color:#fff; border:0; border-radius:6px; padding:8px 24px; font-size:14px; font-weight:600; cursor:pointer; }
		.dp-fcps-btn:hover { background:#4a3a8a; }
		.dp-fcps-code { background:#f4f4f6; border:1px solid #e2e2e6; border-radius:5px; padding:2px 6px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; }
		.dp-fcps-uitleg { border-top:1px solid #eee; margin-top:24px; padding-top:18px; font-size:13px; color:#555; }
		.dp-fcps-uitleg p { margin:0 0 8px; }
	</style>

	<div class="dp-fcps-card">
		<div class="dp-fcps-stat">
			<div class="dp-fcps-box">
				<span class="dp-fcps-num"><?php echo esc_html( number_format_i18n( $gevuld ) ); ?></span>
				<span class="dp-fcps-label">producten met specificaties</span>
			</div>
			<div class="dp-fcps-box">
				<span class="dp-fcps-num"><?php echo esc_html( number_format_i18n( max( 0, $producten - $gevuld ) ) ); ?></span>
				<span class="dp-fcps-label">nog zonder</span>
			</div>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'dp_fc_specs' ); ?>

			<div class="dp-fcps-veld">
				<strong>Kop boven de tabel</strong>
				<input type="text" class="regular-text" name="dp_fc_specs_titel"
					value="<?php echo esc_attr( get_option( 'dp_fc_specs_titel', 'Specificaties' ) ); ?>">
				<p class="dp-fcps-hint">
					Geldt voor de standaard productpagina. Bouw je de pagina in Bricks, dan zet je de kop
					daar als gewoon kop-element neer — de tag levert alleen de tabel.
				</p>
			</div>

			<div class="dp-fcps-veld">
				<strong>Plaatsing op de productpagina</strong>
				<label>
					<input type="radio" name="dp_fc_specs_layout" value="naast" <?php checked( $layout, 'naast' ); ?>>
					Naast de omschrijving, in twee kolommen
				</label>
				<label>
					<input type="radio" name="dp_fc_specs_layout" value="onder" <?php checked( $layout, 'onder' ); ?>>
					Onder de omschrijving, over de volle breedte
				</label>
				<p class="dp-fcps-hint">
					Onder 60rem stapelen de twee kolommen sowieso — een specificatietabel naast een smalle
					tekstkolom leest niet.
				</p>
			</div>

			<div class="dp-fcps-veld">
				<strong>Waar vul je de specificaties in</strong>
				<label>
					<input type="hidden" name="dp_fc_specs_menu" value="0">
					<input type="checkbox" name="dp_fc_specs_menu" value="1" <?php checked( (bool) get_option( 'dp_fc_specs_menu', true ) ); ?>>
					Zet het klassieke productscherm terug in het WP-menu
				</label>
				<p class="dp-fcps-hint">
					FluentCart verbergt zijn eigen product-CPT uit het menu en stuurt bewerklinks door naar
					zijn Vue-beheer. De metabox met de specificaties zit op het klassieke scherm. Met deze
					instelling aan heb je twee plekken waar een product bewerkt wordt: FluentCart voor prijs
					en voorraad, het klassieke scherm voor de specificaties. Uit? Dan blijft de metabox
					bereikbaar via <code class="dp-fcps-code">/wp-admin/post.php?post=&lt;ID&gt;&amp;action=edit</code>.
				</p>
			</div>

			<?php submit_button( 'Opslaan', 'primary', 'submit', false, [ 'class' => 'dp-fcps-btn' ] ); ?>
		</form>

		<div class="dp-fcps-uitleg">
			<p>
				Invoer is een specificatie per regel, als <code class="dp-fcps-code">Label: waarde</code>.
				Er wordt op de eerste dubbele punt gesplitst, dus een waarde mag er zelf ook een bevatten.
			</p>
			<?php if ( $bricks ) : ?>
				<p>
					In Bricks: gebruik de dynamic tag <code class="dp-fcps-code">{dp_fc_specs}</code> in een
					tekstelement. Nodig omdat het element <em>Product Content</em> de omschrijving
					rechtstreeks uit <code class="dp-fcps-code">post_content</code> leest, waardoor
					<code class="dp-fcps-code">the_content</code> niet draait en de tabel daar niet vanzelf
					verschijnt.
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
