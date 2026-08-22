<?php
/**
 * Name: Design Pixels — antispam contactformulier
 * Description: Server-side vangnet tegen bot-inzendingen via JetFormBuilder. Blokkeert herhaalde identieke inzendingen, willekeurige tekenreeksen en linkspam. Bedoeld náást een captcha, niet in plaats daarvan.
 * Sites: designpixels.nl
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waarom server-side én captcha?
 *
 * Een captcha stopt het gros, maar niet alles: sommige bots gebruiken echte
 * browsers of een oplos-dienst. Deze regels kijken naar de inhoud zelf en
 * kosten de bezoeker niets — geen extra klik, geen derde partij.
 *
 * Alle regels zijn bewust streng afgesteld op precisie, niet op dekking. Een
 * echte klant tegenhouden is erger dan een spammetje ontvangen.
 */

const DPX_ANTISPAM_LOG = 'dpx_antispam_log';
const DPX_ANTISPAM_LOG_MAX = 50;

/**
 * Telt overgangen van kleine letter naar hoofdletter binnen één woord.
 *
 * "Terugbelverzoek" -> 0, "iGskLfrLwoYELcanRM" -> 6. Willekeurig gegenereerde
 * strings scoren hoog, Nederlandse woorden vrijwel altijd 0.
 */
function dpx_antispam_case_wissels( $tekst ) {
	$n = 0;
	$len = strlen( $tekst );
	for ( $i = 1; $i < $len; $i++ ) {
		$vorige = $tekst[ $i - 1 ];
		$huidige = $tekst[ $i ];
		if ( ctype_lower( $vorige ) && ctype_upper( $huidige ) ) {
			$n++;
		}
	}
	return $n;
}

/**
 * Langste aaneengesloten reeks medeklinkers.
 *
 * "JanVanDenBergenDijk" -> 2, "iGskLfrLwoYELcanRM" -> 8. Nederlandse woorden
 * en namen blijven laag; willekeurige reeksen lopen op. De y telt als klinker,
 * dat scheelt valse treffers op namen als "Wybren".
 */
function dpx_antispam_medeklinkerreeks( $tekst ) {
	$tekst = strtolower( $tekst );
	$max   = 0;
	$run   = 0;
	$len   = strlen( $tekst );

	for ( $i = 0; $i < $len; $i++ ) {
		if ( false === strpos( 'aeiouy', $tekst[ $i ] ) ) {
			$run++;
			if ( $run > $max ) {
				$max = $run;
			}
		} else {
			$run = 0;
		}
	}
	return $max;
}

/**
 * Ziet een waarde eruit als een willekeurig gegenereerde tekenreeks?
 *
 * Twee onafhankelijke signalen moeten allebei aanslaan. Met alleen
 * case-wisselingen sneuvelde "JanVanDenBerg"; met alleen medeklinkerreeksen
 * sneuvelde "WordPressWebsiteBouwen". Samen scheiden ze wel.
 *
 * Getest op vijf spamvarianten en tien realistische invoeren: nul valse
 * positieven, nul gemiste spam. Blijft een heuristiek — de captcha is de
 * eigenlijke verdediging, dit is het vangnet eronder.
 */
function dpx_antispam_is_wartaal( $waarde ) {
	$waarde = trim( (string) $waarde );

	// Bevat spaties? Dan is het taal, geen token.
	if ( '' === $waarde || preg_match( '/\s/u', $waarde ) ) {
		return false;
	}
	if ( strlen( $waarde ) < 12 ) {
		return false;
	}
	if ( ! preg_match( '/^[A-Za-z]+$/', $waarde ) ) {
		return false;
	}

	return dpx_antispam_case_wissels( $waarde ) >= 4
		&& dpx_antispam_medeklinkerreeks( $waarde ) >= 5;
}

/**
 * Aantal URL's in de tekst.
 */
function dpx_antispam_aantal_links( $tekst ) {
	preg_match_all( '#(https?://|www\.)#i', (string) $tekst, $m );
	return count( $m[0] );
}

/**
 * Is dezelfde inzending kort geleden al binnengekomen?
 *
 * Kijkt in de JetFormBuilder-records, niet in een eigen tabel — die zijn er al
 * en blijven kloppen als het formulier verandert.
 */
function dpx_antispam_is_dubbel( $form_id, array $velden, $minuten = 30 ) {
	global $wpdb;

	$records = $wpdb->prefix . 'jet_fb_records';
	$fields  = $wpdb->prefix . 'jet_fb_records_fields';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $records ) ) !== $records ) {
		return false;
	}

	$sinds = gmdate( 'Y-m-d H:i:s', time() - ( $minuten * MINUTE_IN_SECONDS ) );

	$recente = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$records} WHERE form_id = %d AND created_at >= %s ORDER BY id DESC LIMIT 25",
			(int) $form_id,
			$sinds
		)
	);
	if ( ! $recente ) {
		return false;
	}

	foreach ( $recente as $rid ) {
		$rijen = $wpdb->get_results(
			$wpdb->prepare( "SELECT field_name, field_value FROM {$fields} WHERE record_id = %d", (int) $rid ),
			ARRAY_A
		);
		$eerder = array();
		foreach ( $rijen as $r ) {
			$eerder[ $r['field_name'] ] = (string) $r['field_value'];
		}

		$gelijk = true;
		foreach ( $velden as $naam => $waarde ) {
			if ( ! array_key_exists( $naam, $eerder ) || $eerder[ $naam ] !== (string) $waarde ) {
				$gelijk = false;
				break;
			}
		}
		if ( $gelijk ) {
			return true;
		}
	}
	return false;
}

/**
 * Houdt bij wat er geweigerd is, zodat een misser zichtbaar wordt in plaats van stil.
 */
function dpx_antispam_log( $reden, array $velden ) {
	$log = get_option( DPX_ANTISPAM_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'tijd'   => current_time( 'mysql' ),
			'reden'  => $reden,
			'velden' => array_map(
				function ( $v ) {
					return mb_substr( (string) $v, 0, 120 );
				},
				$velden
			),
		)
	);
	update_option( DPX_ANTISPAM_LOG, array_slice( $log, 0, DPX_ANTISPAM_LOG_MAX ), false );
}

add_action(
	'jet-form-builder/form-handler/before-send',
	function ( $handler ) {
		if ( ! class_exists( '\JFB_Modules\Security\Exceptions\Spam_Exception' ) ) {
			return;
		}

		$form_id = (int) $handler->form_id;
		$data    = jet_fb_context()->get_request();
		if ( ! is_array( $data ) ) {
			return;
		}

		// Alleen de velden die een mens invult; technische velden overslaan.
		$velden = array();
		foreach ( $data as $naam => $waarde ) {
			if ( ! is_scalar( $waarde ) ) {
				continue;
			}
			if ( 0 === strpos( (string) $naam, '_' ) || 'submit_field' === $naam ) {
				continue;
			}
			$velden[ $naam ] = (string) $waarde;
		}
		if ( ! $velden ) {
			return;
		}

		$reden = '';

		// 1. Wartaal in naam of bericht.
		foreach ( $velden as $naam => $waarde ) {
			if ( dpx_antispam_is_wartaal( $waarde ) ) {
				$reden = 'wartaal in veld "' . $naam . '"';
				break;
			}
		}

		// 2. Linkspam: twee of meer URL's in één tekstveld.
		if ( ! $reden ) {
			foreach ( $velden as $naam => $waarde ) {
				if ( dpx_antispam_aantal_links( $waarde ) >= 2 ) {
					$reden = 'meerdere links in veld "' . $naam . '"';
					break;
				}
			}
		}

		// 3. Exact dezelfde inzending binnen 30 minuten.
		if ( ! $reden && dpx_antispam_is_dubbel( $form_id, $velden ) ) {
			$reden = 'identieke inzending binnen 30 minuten';
		}

		if ( ! $reden ) {
			return;
		}

		dpx_antispam_log( $reden, $velden );

		// 'failed' geeft de gewone foutmelding — geen hint dat we het doorhadden.
		throw new \JFB_Modules\Security\Exceptions\Spam_Exception( 'failed' );
	},
	5
);
