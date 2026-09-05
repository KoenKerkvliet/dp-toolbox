<?php
/**
 * Module Name: Reviews
 * Description: Productreviews met sterren, geverifieerde koop en moderatie. Werkt op elk berichttype; herkent zelf FluentCart en WooCommerce voor de koopcontrole. Weer te geven met de shortcodes [dp_reviews] en [dp_reviews_summary] of met de twee Bricks-elementen.
 * Category: content
 * Version: 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DP_REVIEWS_VERSION', '1.0.4' );
define( 'DP_REVIEWS_PATH', __DIR__ . '/' );
define( 'DP_REVIEWS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Reviews zijn WordPress-reacties met een eigen type.
 *
 * Waarom geen eigen tabel of eigen berichttype: met reacties krijg je de
 * moderatiewachtrij, de spamafhandeling (Akismet haakt op `preprocess_comment`),
 * de floodcontrole, het zoeken en het exporteren van WordPress cadeau. Een eigen
 * tabel betekent dat je dat allemaal zelf bouwt, en dat je bij het verwijderen
 * van de plugin data achterlaat die niemand meer kan lezen.
 *
 * De prijs: reacties van dit type moeten overal buiten gehouden worden waar
 * WordPress "gewone" reacties verwacht. Dat gebeurt hieronder in
 * dp_reviews_exclude_from_comment_queries().
 */
const DP_REVIEWS_COMMENT_TYPE = 'dp_review';

/* ------------------------------------------------------------------ */
/*  Instellingen                                                        */
/* ------------------------------------------------------------------ */

/**
 * Standaard-berichttypes: de winkel die op deze site draait.
 *
 * Een module die na het aanzetten nergens iets doet, voelt als een module die
 * stuk is. Daarom raden we zelf het juiste berichttype, in plaats van een lege
 * lijst te tonen.
 */
function dp_reviews_default_post_types() {
	if ( post_type_exists( 'fluent-products' ) ) {
		return [ 'fluent-products' ];
	}
	if ( post_type_exists( 'product' ) ) {
		return [ 'product' ];
	}
	return [ 'post' ];
}

function dp_reviews_settings() {
	$defaults = [
		// Waar reviews mogen staan.
		'post_types'  => dp_reviews_default_post_types(),
		// everyone | logged_in | buyers
		'who'         => 'everyone',
		// Nieuwe reviews eerst goedkeuren.
		'moderate'    => true,
		// Titelveld boven de reviewtekst.
		'title_field' => true,
		// JSON-LD met gemiddelde score en de laatste reviews.
		'schema'      => true,
	];

	$saved = get_option( 'dp_reviews_settings', [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}

	$settings = array_merge( $defaults, $saved );

	if ( empty( $settings['post_types'] ) || ! is_array( $settings['post_types'] ) ) {
		$settings['post_types'] = $defaults['post_types'];
	}

	return apply_filters( 'dp_reviews_settings', $settings );
}

/**
 * Mag dit bericht reviews hebben?
 */
function dp_reviews_enabled_for( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return false;
	}
	$settings = dp_reviews_settings();
	return in_array( get_post_type( $post_id ), (array) $settings['post_types'], true );
}

/* ------------------------------------------------------------------ */
/*  Reviews buiten de gewone reactiestromen houden                      */
/* ------------------------------------------------------------------ */

/**
 * Houd reviews uit queries die om "gewone" reacties vragen.
 *
 * Zonder dit duiken reviews op in de widget Recente reacties, in de
 * reactiefeed en in thema's die `get_comments()` zonder type aanroepen. We
 * grijpen alleen in wanneer er géén type gevraagd is: vraagt iets expliciet om
 * ons type, dan is dat een bewuste vraag en laten we hem met rust.
 */
function dp_reviews_exclude_from_comment_queries( $clauses, $query ) {
	if ( is_admin() ) {
		return $clauses;
	}

	$type = $query->query_vars['type'] ?? '';
	if ( ! empty( $type ) || ! empty( $query->query_vars['type__in'] ) ) {
		return $clauses;
	}

	global $wpdb;
	$clauses['where'] .= $wpdb->prepare(
		" AND {$wpdb->comments}.comment_type != %s",
		DP_REVIEWS_COMMENT_TYPE
	);

	return $clauses;
}
add_filter( 'comments_clauses', 'dp_reviews_exclude_from_comment_queries', 10, 2 );

/* ------------------------------------------------------------------ */
/*  Geverifieerde koop                                                  */
/* ------------------------------------------------------------------ */

/**
 * Heeft dit e-mailadres (of deze gebruiker) dit product daadwerkelijk gekocht?
 *
 * Pluggable via het filter `dp_reviews_is_verified`: een site met een andere
 * winkel haakt daarop in en hoeft de module niet aan te passen. Ingebouwd zitten
 * FluentCart en WooCommerce.
 */
function dp_reviews_is_verified_buyer( $post_id, $email, $user_id = 0 ) {
	$post_id = (int) $post_id;
	$email   = strtolower( trim( (string) $email ) );
	$user_id = (int) $user_id;

	$verified = null;

	// WooCommerce heeft er een eigen functie voor.
	if ( function_exists( 'wc_customer_bought_product' ) && get_post_type( $post_id ) === 'product' ) {
		$verified = (bool) wc_customer_bought_product( $email, $user_id, $post_id );
	}

	// FluentCart: een regel in een betaalde order die naar dit product wijst.
	if ( null === $verified && get_post_type( $post_id ) === 'fluent-products' ) {
		$verified = dp_reviews_fluentcart_bought( $post_id, $email, $user_id );
	}

	return (bool) apply_filters( 'dp_reviews_is_verified', (bool) $verified, $post_id, $email, $user_id );
}

/**
 * FluentCart-variant van de koopcontrole.
 *
 * De koppeling loopt via drie tabellen: een orderregel wijst met `post_id` naar
 * het product, de order wijst met `customer_id` naar de klant, en de klant heeft
 * een e-mailadres. Een order telt als koop zodra hij betaald is; een deels
 * terugbetaalde order telt ook, want die is wel degelijk gekocht en gebruikt.
 */
function dp_reviews_fluentcart_bought( $post_id, $email, $user_id = 0 ) {
	global $wpdb;

	$orders    = $wpdb->prefix . 'fct_orders';
	$items     = $wpdb->prefix . 'fct_order_items';
	$customers = $wpdb->prefix . 'fct_customers';

	// Tabellen ontbreken (FluentCart uitgezet): dan is er niets te verifiëren.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items ) ) !== $items ) {
		return false;
	}

	$where  = [];
	$params = [ $post_id ];

	if ( $email ) {
		$where[]  = 'c.email = %s';
		$params[] = $email;
	}
	if ( $user_id ) {
		$where[]  = 'c.user_id = %d';
		$params[] = $user_id;
	}
	if ( ! $where ) {
		return false;
	}

	$sql = "SELECT o.id
			FROM {$items} i
			INNER JOIN {$orders} o ON o.id = i.order_id
			INNER JOIN {$customers} c ON c.id = o.customer_id
			WHERE i.post_id = %d
			  AND o.payment_status IN ('paid', 'partially_refunded')
			  AND ( " . implode( ' OR ', $where ) . ' )
			LIMIT 1';

	return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
}

/* ------------------------------------------------------------------ */
/*  Ophalen en samenvatten                                              */
/* ------------------------------------------------------------------ */

/**
 * De goedgekeurde reviews van een bericht, nieuwste eerst.
 */
function dp_reviews_get( $post_id, $number = 0 ) {
	$args = [
		'post_id' => (int) $post_id,
		'type'    => DP_REVIEWS_COMMENT_TYPE,
		'status'  => 'approve',
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	];
	if ( $number > 0 ) {
		$args['number'] = (int) $number;
	}

	return get_comments( $args );
}

/**
 * Aantal, gemiddelde en verdeling.
 *
 * Wordt bij elke wijziging herberekend en in post meta gezet, niet in een
 * transient: zo kun je er later ook op sorteren of filteren in een query, en
 * overleeft het een leeggemaakte objectcache.
 */
function dp_reviews_recalculate( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return;
	}

	$reviews = dp_reviews_get( $post_id );
	$verdeling = [ 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ];
	$som       = 0;
	$aantal    = 0;

	foreach ( $reviews as $review ) {
		$score = (int) get_comment_meta( $review->comment_ID, 'dp_rating', true );
		if ( $score < 1 || $score > 5 ) {
			continue;
		}
		$verdeling[ $score ]++;
		$som += $score;
		$aantal++;
	}

	if ( $aantal ) {
		update_post_meta( $post_id, '_dp_reviews_count', $aantal );
		update_post_meta( $post_id, '_dp_reviews_avg', round( $som / $aantal, 2 ) );
		update_post_meta( $post_id, '_dp_reviews_dist', $verdeling );
	} else {
		delete_post_meta( $post_id, '_dp_reviews_count' );
		delete_post_meta( $post_id, '_dp_reviews_avg' );
		delete_post_meta( $post_id, '_dp_reviews_dist' );
	}
}

function dp_reviews_summary( $post_id ) {
	$post_id = (int) $post_id;

	return [
		'count' => (int) get_post_meta( $post_id, '_dp_reviews_count', true ),
		'avg'   => (float) get_post_meta( $post_id, '_dp_reviews_avg', true ),
		'dist'  => (array) ( get_post_meta( $post_id, '_dp_reviews_dist', true ) ?: [] ),
	];
}

/** Herberekenen zodra er iets aan een review verandert. */
function dp_reviews_touch_from_comment( $comment_id, $comment = null ) {
	$comment = $comment instanceof WP_Comment ? $comment : get_comment( $comment_id );
	if ( ! $comment || $comment->comment_type !== DP_REVIEWS_COMMENT_TYPE ) {
		return;
	}
	dp_reviews_recalculate( $comment->comment_post_ID );
}
add_action( 'wp_insert_comment', 'dp_reviews_touch_from_comment', 10, 2 );
add_action( 'edit_comment', 'dp_reviews_touch_from_comment', 10, 2 );
add_action( 'deleted_comment', 'dp_reviews_touch_from_comment', 10, 2 );
add_action( 'trashed_comment', 'dp_reviews_touch_from_comment', 10, 2 );
add_action( 'untrashed_comment', 'dp_reviews_touch_from_comment', 10, 2 );
add_action( 'transition_comment_status', function ( $new, $old, $comment ) {
	dp_reviews_touch_from_comment( $comment->comment_ID, $comment );
}, 10, 3 );

/* ------------------------------------------------------------------ */
/*  Weergavehulpjes                                                     */
/* ------------------------------------------------------------------ */

/**
 * Sterrenbalk.
 *
 * Twee lagen tekst met een breedte in procenten: geen afbeeldingen, geen
 * lettertype van derden, en halve sterren komen er gratis uit. Het label is
 * voor schermlezers; de sterren zelf staan op aria-hidden.
 */
function dp_reviews_stars_html( $score, $label = '' ) {
	$score   = max( 0, min( 5, (float) $score ) );
	$breedte = ( $score / 5 ) * 100;

	if ( $label === '' ) {
		$label = sprintf(
			/* translators: %s: score van 0 tot 5 */
			__( '%s van de 5 sterren', 'dp-toolbox' ),
			number_format_i18n( $score, 1 )
		);
	}

	return sprintf(
		'<span class="dp-rv-stars" role="img" aria-label="%1$s"><span class="dp-rv-stars__vol" style="width:%2$s%%" aria-hidden="true"></span></span>',
		esc_attr( $label ),
		esc_attr( round( $breedte, 2 ) )
	);
}

/**
 * Mag de huidige bezoeker een review plaatsen op dit bericht?
 *
 * Geeft true terug, of een string met de reden waarom niet — die tonen we
 * gewoon aan de bezoeker in plaats van het formulier stilzwijgend weg te laten.
 */
function dp_reviews_may_submit( $post_id ) {
	$settings = dp_reviews_settings();

	if ( $settings['who'] === 'logged_in' && ! is_user_logged_in() ) {
		return __( 'Log in om een review te plaatsen.', 'dp-toolbox' );
	}

	if ( $settings['who'] === 'buyers' ) {
		if ( ! is_user_logged_in() ) {
			return __( 'Alleen klanten die dit product gekocht hebben kunnen een review plaatsen. Log in met het account waarmee je bestelde.', 'dp-toolbox' );
		}
		$user = wp_get_current_user();
		if ( ! dp_reviews_is_verified_buyer( $post_id, $user->user_email, $user->ID ) ) {
			return __( 'Alleen klanten die dit product gekocht hebben kunnen er een review over plaatsen.', 'dp-toolbox' );
		}
	}

	return true;
}

/* ------------------------------------------------------------------ */
/*  Formulier verwerken                                                 */
/* ------------------------------------------------------------------ */

add_action( 'admin_post_nopriv_dp_review_submit', 'dp_reviews_handle_submit' );
add_action( 'admin_post_dp_review_submit', 'dp_reviews_handle_submit' );

function dp_reviews_handle_submit() {
	$post_id = isset( $_POST['dp_rv_post'] ) ? absint( $_POST['dp_rv_post'] ) : 0;
	$terug   = $post_id ? get_permalink( $post_id ) : home_url( '/' );

	$fout = function ( $code ) use ( $terug ) {
		wp_safe_redirect( add_query_arg( 'dp_review', $code, $terug ) . '#dp-reviews' );
		exit;
	};

	if ( ! $post_id || ! dp_reviews_enabled_for( $post_id ) ) {
		$fout( 'error' );
	}

	if ( ! isset( $_POST['dp_rv_nonce'] ) || ! wp_verify_nonce( $_POST['dp_rv_nonce'], 'dp_review_' . $post_id ) ) {
		$fout( 'error' );
	}

	// Honeypot: een veld dat verstopt staat en dus leeg hoort te zijn.
	if ( ! empty( $_POST['dp_rv_website'] ) ) {
		$fout( 'error' );
	}

	// Tijdslot: een mens doet er langer dan vier seconden over.
	$gestart = isset( $_POST['dp_rv_t'] ) ? absint( $_POST['dp_rv_t'] ) : 0;
	if ( ! $gestart || ( time() - $gestart ) < 4 ) {
		$fout( 'error' );
	}

	$mag = dp_reviews_may_submit( $post_id );
	if ( $mag !== true ) {
		$fout( 'denied' );
	}

	$score = isset( $_POST['dp_rv_rating'] ) ? absint( $_POST['dp_rv_rating'] ) : 0;
	if ( $score < 1 || $score > 5 ) {
		$fout( 'rating' );
	}

	$tekst = isset( $_POST['dp_rv_content'] ) ? trim( wp_unslash( $_POST['dp_rv_content'] ) ) : '';
	if ( mb_strlen( $tekst ) < 10 ) {
		$fout( 'short' );
	}

	$titel = isset( $_POST['dp_rv_title'] ) ? sanitize_text_field( wp_unslash( $_POST['dp_rv_title'] ) ) : '';

	if ( is_user_logged_in() ) {
		$user  = wp_get_current_user();
		$naam  = $user->display_name;
		$email = $user->user_email;
		$uid   = $user->ID;
	} else {
		$naam  = isset( $_POST['dp_rv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['dp_rv_name'] ) ) : '';
		$email = isset( $_POST['dp_rv_email'] ) ? sanitize_email( wp_unslash( $_POST['dp_rv_email'] ) ) : '';
		$uid   = 0;

		if ( $naam === '' || ! is_email( $email ) ) {
			$fout( 'error' );
		}
	}

	$settings   = dp_reviews_settings();
	$geverifieerd = dp_reviews_is_verified_buyer( $post_id, $email, $uid );

	$data = [
		'comment_post_ID'      => $post_id,
		'comment_author'       => $naam,
		'comment_author_email' => $email,
		'comment_content'      => wp_kses_post( $tekst ),
		'comment_type'         => DP_REVIEWS_COMMENT_TYPE,
		'user_id'              => $uid,
		'comment_approved'     => $settings['moderate'] ? 0 : 1,
	];

	// wp_new_comment() geeft ons floodcontrole, de verboden-woordenlijst en
	// Akismet; wp_insert_comment() slaat dat allemaal over.
	$comment_id = wp_new_comment( wp_slash( $data ), true );

	if ( is_wp_error( $comment_id ) || ! $comment_id ) {
		$fout( 'error' );
	}

	add_comment_meta( $comment_id, 'dp_rating', $score );
	if ( $titel !== '' ) {
		add_comment_meta( $comment_id, 'dp_title', $titel );
	}
	if ( $geverifieerd ) {
		add_comment_meta( $comment_id, 'dp_verified', 1 );
	}

	// Nog een keer herrekenen. Bij `wp_new_comment` vuurt `wp_insert_comment`
	// al, maar het cijfer staat op dat moment nog niet in de meta — de review
	// zou dan als scoreloos worden overgeslagen en niet meetellen.
	dp_reviews_recalculate( $post_id );

	$status = wp_get_comment_status( $comment_id );

	wp_safe_redirect(
		add_query_arg( 'dp_review', $status === 'approved' ? 'ok' : 'pending', $terug ) . '#dp-reviews'
	);
	exit;
}

/* ------------------------------------------------------------------ */
/*  Weergave                                                            */
/* ------------------------------------------------------------------ */

function dp_reviews_enqueue_assets() {
	$css = DP_REVIEWS_PATH . 'assets/css/frontend.css';
	wp_enqueue_style(
		'dp-reviews',
		DP_REVIEWS_URL . 'assets/css/frontend.css',
		[],
		file_exists( $css ) ? filemtime( $css ) : DP_REVIEWS_VERSION
	);
}

/**
 * De volledige reviewsectie: samenvatting, lijst en formulier.
 */
function dp_reviews_render( $atts = [] ) {
	$atts = shortcode_atts( [
		'post_id' => 0,
		'titel'   => __( 'Beoordelingen', 'dp-toolbox' ),
		'form'    => 'ja',
	], $atts, 'dp_reviews' );

	$post_id = (int) $atts['post_id'] ?: get_the_ID();

	if ( ! dp_reviews_enabled_for( $post_id ) ) {
		return '';
	}

	dp_reviews_enqueue_assets();

	$samenvatting = dp_reviews_summary( $post_id );
	$reviews      = dp_reviews_get( $post_id );
	$melding      = isset( $_GET['dp_review'] ) ? sanitize_key( $_GET['dp_review'] ) : '';

	ob_start();
	?>
	<section class="dp-rv" id="dp-reviews">
		<h2 class="dp-rv__kop"><?php echo esc_html( $atts['titel'] ); ?></h2>

		<?php if ( $melding ) : ?>
			<?php
			$meldingen = [
				'ok'      => [ 'ok', __( 'Bedankt, je review staat erbij.', 'dp-toolbox' ) ],
				'pending' => [ 'ok', __( 'Bedankt. We lezen je review eerst even na; daarna verschijnt hij hier.', 'dp-toolbox' ) ],
				'rating'  => [ 'fout', __( 'Kies eerst een aantal sterren.', 'dp-toolbox' ) ],
				'short'   => [ 'fout', __( 'Schrijf iets meer, minimaal tien tekens.', 'dp-toolbox' ) ],
				'denied'  => [ 'fout', __( 'Je kunt op dit product geen review plaatsen.', 'dp-toolbox' ) ],
				'error'   => [ 'fout', __( 'Er ging iets mis. Probeer het opnieuw.', 'dp-toolbox' ) ],
			];
			if ( isset( $meldingen[ $melding ] ) ) :
				?>
				<p class="dp-rv__melding dp-rv__melding--<?php echo esc_attr( $meldingen[ $melding ][0] ); ?>" role="status">
					<?php echo esc_html( $meldingen[ $melding ][1] ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $samenvatting['count'] > 0 ) : ?>
			<div class="dp-rv__samenvatting">
				<div class="dp-rv__cijfer">
					<span class="dp-rv__gemiddelde"><?php echo esc_html( number_format_i18n( $samenvatting['avg'], 1 ) ); ?></span>
					<?php echo dp_reviews_stars_html( $samenvatting['avg'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="dp-rv__aantal">
						<?php
						printf(
							esc_html( _n( '%s beoordeling', '%s beoordelingen', $samenvatting['count'], 'dp-toolbox' ) ),
							esc_html( number_format_i18n( $samenvatting['count'] ) )
						);
						?>
					</span>
				</div>

				<ul class="dp-rv__verdeling">
					<?php for ( $ster = 5; $ster >= 1; $ster-- ) : ?>
						<?php
						$n       = (int) ( $samenvatting['dist'][ $ster ] ?? 0 );
						$breedte = $samenvatting['count'] ? ( $n / $samenvatting['count'] ) * 100 : 0;
						?>
						<li class="dp-rv__verdeling-rij">
							<span class="dp-rv__verdeling-label">
								<?php
								printf(
									esc_html( _n( '%s ster', '%s sterren', $ster, 'dp-toolbox' ) ),
									esc_html( $ster )
								);
								?>
							</span>
							<span class="dp-rv__balk"><span class="dp-rv__balk-vol" style="width:<?php echo esc_attr( round( $breedte, 2 ) ); ?>%"></span></span>
							<span class="dp-rv__verdeling-n"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
						</li>
					<?php endfor; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( $reviews ) : ?>
			<ol class="dp-rv__lijst">
				<?php foreach ( $reviews as $review ) : ?>
					<?php
					$score       = (int) get_comment_meta( $review->comment_ID, 'dp_rating', true );
					$titel       = (string) get_comment_meta( $review->comment_ID, 'dp_title', true );
					$geverifieerd = (bool) get_comment_meta( $review->comment_ID, 'dp_verified', true );
					?>
					<li class="dp-rv__item">
						<div class="dp-rv__item-kop">
							<?php echo dp_reviews_stars_html( $score ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( $titel !== '' ) : ?>
								<h3 class="dp-rv__item-titel"><?php echo esc_html( $titel ); ?></h3>
							<?php endif; ?>
						</div>

						<div class="dp-rv__item-tekst"><?php echo wpautop( wp_kses_post( $review->comment_content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

						<p class="dp-rv__item-meta">
							<span class="dp-rv__auteur"><?php echo esc_html( $review->comment_author ); ?></span>
							<?php if ( $geverifieerd ) : ?>
								<span class="dp-rv__badge" title="<?php esc_attr_e( 'Deze klant heeft dit product bij ons gekocht', 'dp-toolbox' ); ?>"><?php esc_html_e( 'Geverifieerde koop', 'dp-toolbox' ); ?></span>
							<?php endif; ?>
							<time datetime="<?php echo esc_attr( get_comment_date( 'c', $review ) ); ?>"><?php echo esc_html( get_comment_date( '', $review ) ); ?></time>
						</p>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<p class="dp-rv__leeg"><?php esc_html_e( 'Er is nog geen beoordeling voor dit product. Die van jou is dus de eerste.', 'dp-toolbox' ); ?></p>
		<?php endif; ?>

		<?php if ( $atts['form'] !== 'nee' ) : ?>
			<?php echo dp_reviews_form_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'dp_reviews', 'dp_reviews_render' );

/**
 * Het formulier.
 */
function dp_reviews_form_html( $post_id ) {
	$mag      = dp_reviews_may_submit( $post_id );
	$settings = dp_reviews_settings();

	ob_start();

	if ( $mag !== true ) {
		printf( '<p class="dp-rv__gesloten">%s</p>', esc_html( $mag ) );
		return ob_get_clean();
	}
	?>
	<form class="dp-rv__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<h3 class="dp-rv__form-kop"><?php esc_html_e( 'Schrijf een beoordeling', 'dp-toolbox' ); ?></h3>

		<input type="hidden" name="action" value="dp_review_submit">
		<input type="hidden" name="dp_rv_post" value="<?php echo esc_attr( $post_id ); ?>">
		<input type="hidden" name="dp_rv_t" value="<?php echo esc_attr( time() ); ?>">
		<?php wp_nonce_field( 'dp_review_' . $post_id, 'dp_rv_nonce' ); ?>

		<?php /* Honeypot. Verstopt met CSS én met aria-hidden, en buiten de tabvolgorde. */ ?>
		<div class="dp-rv__hp" aria-hidden="true">
			<label><?php esc_html_e( 'Website', 'dp-toolbox' ); ?>
				<input type="text" name="dp_rv_website" tabindex="-1" autocomplete="off">
			</label>
		</div>

		<?php
		/**
		 * All-In-One Security zet verborgen sleutelvelden in het standaard
		 * reactieformulier en markeert elke reactie zonder die velden als spam.
		 * Ons formulier is een reactieformulier, dus het hoort ze mee te sturen.
		 *
		 * Zonder dit belandt elke review van een uitgelogde bezoeker in de
		 * spammap in plaats van in de moderatiewachtrij — zonder melding, want
		 * AIOS grijpt in op `pre_comment_approved`, ná onze eigen controles.
		 * We schakelen AIOS dus niet uit, we voldoen aan de eis.
		 */
		if ( class_exists( 'AIOWPSecurity_Comment' ) && method_exists( 'AIOWPSecurity_Comment', 'insert_antibot_keys_in_comment_form' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AIOS escapet zijn eigen velden
			echo AIOWPSecurity_Comment::insert_antibot_keys_in_comment_form();
		}
		?>

		<fieldset class="dp-rv__veld dp-rv__sterren-veld">
			<legend><?php esc_html_e( 'Jouw score', 'dp-toolbox' ); ?> <span class="dp-rv__verplicht">*</span></legend>
			<div class="dp-rv__sterren-keuze">
				<?php for ( $ster = 5; $ster >= 1; $ster-- ) : ?>
					<input type="radio" id="dp-rv-ster-<?php echo esc_attr( $ster ); ?>" name="dp_rv_rating" value="<?php echo esc_attr( $ster ); ?>" required>
					<label for="dp-rv-ster-<?php echo esc_attr( $ster ); ?>">
						<span class="screen-reader-text">
							<?php
							printf(
								esc_html( _n( '%s ster', '%s sterren', $ster, 'dp-toolbox' ) ),
								esc_html( $ster )
							);
							?>
						</span>
					</label>
				<?php endfor; ?>
			</div>
		</fieldset>

		<?php if ( ! empty( $settings['title_field'] ) ) : ?>
			<p class="dp-rv__veld">
				<label for="dp-rv-title"><?php esc_html_e( 'Kop', 'dp-toolbox' ); ?></label>
				<input type="text" id="dp-rv-title" name="dp_rv_title" maxlength="80">
			</p>
		<?php endif; ?>

		<p class="dp-rv__veld">
			<label for="dp-rv-content"><?php esc_html_e( 'Je beoordeling', 'dp-toolbox' ); ?> <span class="dp-rv__verplicht">*</span></label>
			<textarea id="dp-rv-content" name="dp_rv_content" rows="5" required minlength="10"></textarea>
		</p>

		<?php if ( ! is_user_logged_in() ) : ?>
			<div class="dp-rv__rij">
				<p class="dp-rv__veld">
					<label for="dp-rv-name"><?php esc_html_e( 'Naam', 'dp-toolbox' ); ?> <span class="dp-rv__verplicht">*</span></label>
					<input type="text" id="dp-rv-name" name="dp_rv_name" required autocomplete="name">
				</p>
				<p class="dp-rv__veld">
					<label for="dp-rv-email"><?php esc_html_e( 'E-mailadres', 'dp-toolbox' ); ?> <span class="dp-rv__verplicht">*</span></label>
					<input type="email" id="dp-rv-email" name="dp_rv_email" required autocomplete="email">
					<span class="dp-rv__hint"><?php esc_html_e( 'Niet zichtbaar bij je review. We gebruiken het om te kijken of je hier besteld hebt.', 'dp-toolbox' ); ?></span>
				</p>
			</div>
		<?php endif; ?>

		<button type="submit" class="dp-rv__knop"><?php esc_html_e( 'Plaatsen', 'dp-toolbox' ); ?></button>

		<?php if ( ! empty( $settings['moderate'] ) ) : ?>
			<p class="dp-rv__hint dp-rv__hint--blok"><?php esc_html_e( 'We lezen elke beoordeling na voordat hij online komt.', 'dp-toolbox' ); ?></p>
		<?php endif; ?>
	</form>
	<?php
	return ob_get_clean();
}

/**
 * Compacte samenvatting: sterren, gemiddelde en een sprong naar de reviews.
 * Bedoeld voor vlak onder de producttitel.
 */
function dp_reviews_summary_render( $atts = [] ) {
	$atts = shortcode_atts( [
		'post_id' => 0,
		'leeg'    => 'verbergen', // verbergen | uitnodigen
	], $atts, 'dp_reviews_summary' );

	$post_id = (int) $atts['post_id'] ?: get_the_ID();

	if ( ! dp_reviews_enabled_for( $post_id ) ) {
		return '';
	}

	$s = dp_reviews_summary( $post_id );

	if ( ! $s['count'] ) {
		if ( $atts['leeg'] !== 'uitnodigen' ) {
			return '';
		}
		dp_reviews_enqueue_assets();
		return sprintf(
			'<p class="dp-rv-mini dp-rv-mini--leeg"><a href="#dp-reviews">%s</a></p>',
			esc_html__( 'Nog geen beoordelingen — schrijf de eerste', 'dp-toolbox' )
		);
	}

	dp_reviews_enqueue_assets();

	return sprintf(
		'<p class="dp-rv-mini"><a href="#dp-reviews">%1$s<span class="dp-rv-mini__cijfer">%2$s</span><span class="dp-rv-mini__aantal">%3$s</span></a></p>',
		dp_reviews_stars_html( $s['avg'] ),
		esc_html( number_format_i18n( $s['avg'], 1 ) ),
		esc_html( sprintf( _n( '%s beoordeling', '%s beoordelingen', $s['count'], 'dp-toolbox' ), number_format_i18n( $s['count'] ) ) )
	);
}
add_shortcode( 'dp_reviews_summary', 'dp_reviews_summary_render' );

/* ------------------------------------------------------------------ */
/*  Gestructureerde data                                                */
/* ------------------------------------------------------------------ */

/**
 * JSON-LD met gemiddelde score en de laatste reviews.
 *
 * Let op wat dit wel en niet doet: Google toont beoordelingssterren voor
 * PRODUCTEN ook wanneer de reviews van je eigen site komen. Voor een bedrijf of
 * dienst (LocalBusiness, Organization) geldt dat niet — reviews over jezelf
 * worden daar als self-serving genegeerd. Deze uitvoer is dus zinvol op een
 * webshop en zinloos op een dienstenpagina.
 */
function dp_reviews_schema() {
	if ( ! is_singular() ) {
		return;
	}

	$settings = dp_reviews_settings();
	if ( empty( $settings['schema'] ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( ! dp_reviews_enabled_for( $post_id ) ) {
		return;
	}

	$s = dp_reviews_summary( $post_id );
	if ( ! $s['count'] ) {
		return;
	}

	$reviews = [];
	foreach ( dp_reviews_get( $post_id, 10 ) as $review ) {
		$score = (int) get_comment_meta( $review->comment_ID, 'dp_rating', true );
		if ( ! $score ) {
			continue;
		}
		$reviews[] = [
			'@type'         => 'Review',
			'reviewRating'  => [
				'@type'       => 'Rating',
				'ratingValue' => $score,
				'bestRating'  => 5,
				'worstRating' => 1,
			],
			'author'        => [
				'@type' => 'Person',
				'name'  => $review->comment_author,
			],
			'datePublished' => get_comment_date( 'c', $review ),
			'reviewBody'    => wp_strip_all_tags( $review->comment_content ),
		];
	}

	/**
	 * De titel rauw uit het bericht, niet via get_the_title().
	 *
	 * Webshopplugins maken `the_title` op hun eigen productpagina leeg, zodat
	 * het thema de titel niet nog een keer toont naast die van hun sjabloon.
	 * FluentCart doet dat bijvoorbeeld. Met get_the_title() zou hier dus een
	 * lege productnaam in de gestructureerde data belanden.
	 */
	$data = [
		'@context'        => 'https://schema.org',
		'@type'           => 'Product',
		'name'            => get_post_field( 'post_title', $post_id ),
		'url'             => get_permalink( $post_id ),
		'aggregateRating' => [
			'@type'       => 'AggregateRating',
			'ratingValue' => $s['avg'],
			'reviewCount' => $s['count'],
			'bestRating'  => 5,
			'worstRating' => 1,
		],
	];

	if ( $reviews ) {
		$data['review'] = $reviews;
	}

	$data = apply_filters( 'dp_reviews_schema', $data, $post_id );

	printf(
		"<script type=\"application/ld+json\">%s</script>\n",
		wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}
add_action( 'wp_footer', 'dp_reviews_schema', 20 );

/* ------------------------------------------------------------------ */
/*  Beheer                                                              */
/* ------------------------------------------------------------------ */

/**
 * Sterren in de reactielijst van WordPress, zodat je in de moderatiewachtrij
 * ziet waar je naar kijkt zonder de review te openen.
 */
add_filter( 'comment_text', function ( $tekst, $comment = null ) {
	if ( ! is_admin() || ! $comment instanceof WP_Comment ) {
		return $tekst;
	}
	if ( $comment->comment_type !== DP_REVIEWS_COMMENT_TYPE ) {
		return $tekst;
	}

	$score = (int) get_comment_meta( $comment->comment_ID, 'dp_rating', true );
	$titel = (string) get_comment_meta( $comment->comment_ID, 'dp_title', true );

	$kop = '<p style="margin:0 0 6px;"><strong>' . esc_html( str_repeat( '★', $score ) . str_repeat( '☆', 5 - $score ) ) . '</strong>';
	if ( $titel !== '' ) {
		$kop .= ' <strong>' . esc_html( $titel ) . '</strong>';
	}
	if ( get_comment_meta( $comment->comment_ID, 'dp_verified', true ) ) {
		$kop .= ' <span style="color:#2a7d2e;">' . esc_html__( '· geverifieerde koop', 'dp-toolbox' ) . '</span>';
	}
	$kop .= '</p>';

	return $kop . $tekst;
}, 10, 2 );

if ( is_admin() ) {
	require_once __DIR__ . '/admin-page.php';
}

/**
 * Bricks-elementen.
 *
 * Niet op `defined( 'BRICKS_VERSION' )` toetsen: modules laden op
 * plugins_loaded, en het thema is dan nog niet ingeladen. De helper van de
 * plugin kijkt naar de themaoptie en werkt wél zo vroeg.
 */
if ( function_exists( 'dp_toolbox_bricks_is_available' ) && dp_toolbox_bricks_is_available() ) {
	require_once __DIR__ . '/bricks.php';
}
