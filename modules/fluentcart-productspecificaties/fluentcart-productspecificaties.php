<?php
/**
 * Module Name: FluentCart Productspecificaties
 * Description: Een specificatietabel bij het product ("Materiaal: steengoed", een per regel). Invoer via een metabox op het klassieke bewerkscherm, weergave als tabel onder of naast de lange omschrijving, en in Bricks als de dynamic tag {dp_fc_specs}.
 * Category: ecommerce
 * Requires: fluent-cart
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DP_FCPS_VERSION', '1.0.0' );

/**
 * FluentCart heeft geen veld voor technische specificaties. Wat het wel heeft:
 * het product-CPT ondersteunt `custom-fields`, en de productpagina laat de lange
 * omschrijving door `the_content` lopen. Daar haken we op aan.
 *
 * Opslag  : post meta `_dp_fc_specs`, een specificatie per regel als "Label: waarde".
 * Invoer  : metabox op het KLASSIEKE bewerkscherm van het product.
 * Weergave: tabel naast of onder de lange omschrijving, plus een Bricks-tag.
 */
const DP_FCPS_META = '_dp_fc_specs';

function dp_fcps_titel( $post_id = 0 ) {
	$titel = (string) get_option( 'dp_fc_specs_titel', 'Specificaties' );

	return apply_filters( 'dp_fc_specs_titel', $titel !== '' ? $titel : 'Specificaties', $post_id );
}

function dp_fcps_layout() {
	return get_option( 'dp_fc_specs_layout', 'naast' ) === 'onder' ? 'onder' : 'naast';
}

/**
 * Zet de ruwe tekst uit de metabox om in paren.
 *
 * Splitst op de EERSTE dubbele punt, zodat een waarde er zelf ook een mag
 * bevatten ("Vaatwasser: ja, tot 60 graden: kort programma").
 */
function dp_fcps_parse( $ruw ) {
	$paren = [];

	foreach ( preg_split( '/\R/', (string) $ruw ) as $regel ) {
		$regel = trim( $regel );

		if ( $regel === '' ) {
			continue;
		}

		$pos = strpos( $regel, ':' );

		if ( $pos === false ) {
			// Geen dubbele punt: hele regel als label, lege waarde. Zo verdwijnt
			// een typefout niet stilzwijgend uit beeld.
			$paren[] = [ 'label' => $regel, 'waarde' => '' ];
			continue;
		}

		$label  = trim( substr( $regel, 0, $pos ) );
		$waarde = trim( substr( $regel, $pos + 1 ) );

		if ( $label === '' ) {
			continue;
		}

		$paren[] = [ 'label' => $label, 'waarde' => $waarde ];
	}

	return $paren;
}

function dp_fcps_heeft_specs( $post_id ) {
	return trim( (string) get_post_meta( $post_id, DP_FCPS_META, true ) ) !== '';
}

/**
 * Alleen de tabel, zonder kop — die staat in het sjabloon of wordt hieronder
 * toegevoegd, afhankelijk van waar hij vandaan gevraagd wordt.
 */
function dp_fcps_tabel_html( $post_id ) {
	$paren = dp_fcps_parse( get_post_meta( $post_id, DP_FCPS_META, true ) );

	if ( empty( $paren ) ) {
		return '';
	}

	$rijen = '';

	foreach ( $paren as $paar ) {
		$rijen .= sprintf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $paar['label'] ),
			esc_html( $paar['waarde'] )
		);
	}

	return '<table class="dp-fc-specs__tabel"><tbody>' . $rijen . '</tbody></table>';
}

/* ------------------------------------------------------------------ */
/*  Invoer                                                             */
/* ------------------------------------------------------------------ */

/**
 * Het klassieke bewerkscherm terugzetten in het menu.
 *
 * FluentCart verbergt zijn product-CPT uit het WP-menu en stuurt
 * get_edit_post_link() door naar zijn eigen Vue-beheer. Het klassieke scherm
 * bestaat wel (show_ui staat aan) maar is zonder directe URL onbereikbaar, en
 * daar zit onze metabox.
 *
 * Prijs daarvan: je hebt twee plekken waar een product bewerkt wordt —
 * FluentCart voor prijzen en voorraad, het klassieke scherm voor de
 * specificaties. Wie dat niet wil, zet de instelling uit; de metabox blijft dan
 * bereikbaar via /wp-admin/post.php?post=<ID>&action=edit.
 */
add_filter( 'fluent_cart/show_standalone_product_menu', function ( $toon ) {
	return (bool) apply_filters( 'dp_fc_specs_standalone_menu', get_option( 'dp_fc_specs_menu', true ) );
}, 20 );

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'dp_fc_specs',
		'Specificaties',
		function ( $post ) {
			wp_nonce_field( 'dp_fc_specs_save', 'dp_fc_specs_nonce' );
			$waarde = get_post_meta( $post->ID, DP_FCPS_META, true );
			?>
			<p style="margin-top:0;color:#50575e;">
				Een specificatie per regel, als <code>Label: waarde</code>.<br>
				Bijvoorbeeld: <code>Materiaal: steengoed</code>
			</p>
			<textarea
				name="dp_fc_specs"
				rows="8"
				style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;"
				placeholder="Materiaal: steengoed&#10;Inhoud: 350 ml&#10;Vaatwasserbestendig: ja"
			><?php echo esc_textarea( $waarde ); ?></textarea>
			<?php
		},
		'fluent-products',
		'normal',
		'default'
	);
} );

add_action( 'save_post_fluent-products', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! isset( $_POST['dp_fc_specs_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['dp_fc_specs_nonce'] ), 'dp_fc_specs_save' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Niet isset() maar array_key_exists: een leeggemaakt veld moet ook opslaan.
	if ( ! array_key_exists( 'dp_fc_specs', $_POST ) ) {
		return;
	}

	$ruw = wp_unslash( $_POST['dp_fc_specs'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- regel voor regel gesaneerd hieronder.
	$ruw = implode( "\n", array_map( 'sanitize_text_field', preg_split( '/\R/', (string) $ruw ) ) );
	$ruw = trim( $ruw );

	if ( $ruw === '' ) {
		delete_post_meta( $post_id, DP_FCPS_META );
	} else {
		update_post_meta( $post_id, DP_FCPS_META, $ruw );
	}
} );

/* ------------------------------------------------------------------ */
/*  Weergave op de standaard productpagina                             */
/* ------------------------------------------------------------------ */

add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || ! is_singular( 'fluent-products' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// FluentCarts eigen renderDescription() draait the_content nog eens over
	// dezelfde post heen. Een keer per post volstaat.
	static $gedaan = [];

	$post_id = get_the_ID();

	if ( isset( $gedaan[ $post_id ] ) ) {
		return $content;
	}

	$tabel = dp_fcps_tabel_html( $post_id );

	if ( $tabel === '' ) {
		return $content;
	}

	$gedaan[ $post_id ] = true;

	$sectie = sprintf(
		'<section class="dp-fc-specs"><h2 class="dp-fc-specs__titel">%1$s</h2>%2$s</section>',
		esc_html( dp_fcps_titel( $post_id ) ),
		$tabel
	);

	if ( dp_fcps_layout() === 'onder' ) {
		return $content . $sectie;
	}

	// Tekst en tabel naast elkaar. De lange omschrijving heeft van zichzelf geen
	// wrapper — WordPress zet de losse p/h2/ul rechtstreeks in de contentkolom —
	// dus die maken we hier, anders valt er niets naast te zetten.
	return sprintf(
		'<div class="dp-fc-onder"><div class="dp-fc-onder__tekst">%1$s</div>%2$s</div>',
		$content,
		$sectie
	);
}, 20 );

add_action( 'wp_head', function () {
	if ( ! is_singular( 'fluent-products' ) ) {
		return;
	}

	// Geen specificaties op dit product? Dan ook geen stylesheet meesturen.
	if ( ! dp_fcps_heeft_specs( get_queried_object_id() ) ) {
		return;
	}
	?>
<style id="dp-fc-specs-css">
<?php if ( dp_fcps_layout() === 'naast' ) : ?>
/* Twee kolommen: omschrijving links, specificaties rechts. Onder 60rem stapelen
   ze, want een specificatietabel naast een smalle tekstkolom leest niet. */
.dp-fc-onder {
	display: grid;
	grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
	gap: var(--space-l, 2rem) var(--space-xl, 3rem);
	align-items: start;
}
@media (max-width: 60rem) {
	.dp-fc-onder { grid-template-columns: minmax(0, 1fr); }
}
/* De eerste alinea van de omschrijving mag niet met een marge beginnen, anders
   staat de tekst lager dan de kop van de tabel ernaast. */
.dp-fc-onder__tekst > *:first-child { margin-block-start: 0; }
<?php endif; ?>

/* display:block is niet overbodig: ACSS zet op ELKE <section> flex-column met
   align-items:center en een container-gap. Zonder deze regel staan de titel en
   de tabel gecentreerd in plaats van links. Op sites zonder ACSS is het een no-op. */
.dp-fc-specs {
	display: block;
	/* ACSS geeft elke <section> ook padding-block en padding-inline: var(--gutter).
	   Die zou de tabel naar binnen duwen ten opzichte van de rest. */
	padding: 0;
	/* Geen eigen bovenmarge: naast de tekst moet de tabel bovenaan uitlijnen,
	   en gestapeld regelt de raster-gap de ruimte. */
	margin-block: 0;
}
.dp-fc-specs__titel {
	margin-block: 0 var(--space-xs, .75rem);
	font-size: var(--text-l, 1.25rem);
	line-height: 1.25;
}
.dp-fc-specs__tabel {
	width: 100%;
	max-width: 46rem;
	border-collapse: collapse;
	font-size: var(--text-s, .9rem);
	line-height: 1.6;
}
.dp-fc-specs__tabel th,
.dp-fc-specs__tabel td {
	padding-block: .6em;
	padding-inline: 0 var(--space-s, 1rem);
	border-block-end: 1px solid var(--neutral-light, #e2ddd8);
	text-align: start;
	vertical-align: top;
}
.dp-fc-specs__tabel th {
	width: 40%;
	min-width: 8rem;
	font-weight: 600;
	color: var(--neutral-semi-dark, #64584f);
}
.dp-fc-specs__tabel tr:last-child th,
.dp-fc-specs__tabel tr:last-child td { border-block-end: 0; }

/* Op smalle schermen naast elkaar houden werkt niet meer; label boven waarde. */
@media (max-width: 30rem) {
	.dp-fc-specs__tabel th,
	.dp-fc-specs__tabel td { display: block; width: auto; }
	.dp-fc-specs__tabel th { border-block-end: 0; padding-block-end: 0; }
}
</style>
	<?php
}, 20 );

/* ------------------------------------------------------------------ */
/*  Weergave in Bricks                                                 */
/* ------------------------------------------------------------------ */

/**
 * Dezelfde tabel als dynamic-data-tag `{dp_fc_specs}`.
 *
 * Nodig omdat het Bricks-element fct-product-content de omschrijving
 * rechtstreeks uit post_content leest en `the_content` dus niet draait — de
 * tabel hierboven verschijnt daar niet vanzelf. De tag geeft de tabel zonder
 * kop terug: die zet je als gewoon kop-element in het sjabloon, zodat hij daar
 * te bewerken is.
 */
if ( function_exists( 'dp_toolbox_bricks_is_available' ) && dp_toolbox_bricks_is_available() ) {

	add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
		$tags[] = [
			'name'  => '{dp_fc_specs}',
			'label' => 'Specificatietabel',
			'group' => 'Product',
		];

		return $tags;
	} );

	add_filter( 'bricks/dynamic_data/render_tag', function ( $tag, $post, $context = 'text' ) {
		if ( trim( (string) $tag, '{}' ) !== 'dp_fc_specs' ) {
			return $tag;
		}

		return $post ? dp_fcps_tabel_html( $post->ID ) : '';
	}, 10, 3 );

	add_filter( 'bricks/dynamic_data/render_content', function ( $content, $post, $context = 'text' ) {
		if ( ! is_string( $content ) || strpos( $content, '{dp_fc_specs}' ) === false ) {
			return $content;
		}

		return str_replace( '{dp_fc_specs}', $post ? dp_fcps_tabel_html( $post->ID ) : '', $content );
	}, 10, 3 );
}

if ( is_admin() ) {
	require_once __DIR__ . '/admin-page.php';
}
