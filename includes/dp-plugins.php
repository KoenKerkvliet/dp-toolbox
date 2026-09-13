<?php
/**
 * DP Plugins — eigen update-kanaal voor de Design Pixels-plugins.
 *
 * Vervangt Git Updater voor DP Toolbox, DP Analytics, DP Cookie Consent en DP
 * Webshop. Waarom een eigen kanaal:
 *
 * - Git Updater vraagt per plugin de GitHub-API. Op gedeelde hosting (Hostinger)
 *   delen tientallen sites één IP, en dan is de limiet van 60 per uur zo op: de
 *   update verschijnt gewoon niet.
 * - Het werkt alleen voor openbare repo's, en niet elke site heeft Git Updater.
 *
 * Werking: één versielijst (manifest.json) op GitHub Pages, met terugval op
 * raw.githubusercontent.com. Eén verzoek per updatecheck in plaats van één per
 * plugin, en geen API-limiet.
 *
 * Veiligheid — dit installeert code op klantsites, dus:
 * - De versielijst is ondertekend met Ed25519. Zonder geldige handtekening van
 *   een sleutel uit dp_toolbox_dpp_sleutels() wordt hij genegeerd.
 * - Een ZIP wordt alleen geïnstalleerd als zijn sha256 exact in die lijst staat.
 *   Een overgenomen GitHub-account kan dus niets uitrollen zonder de geheime
 *   sleutel, die alleen lokaal staat (publish.py).
 * - Alleen slugs met `dp-` en het vaste pad `zips/<slug>/<slug>-<versie>.zip`;
 *   een lijst kan dus nooit een update voor WooCommerce of zo inschuiven.
 * - Een oudere lijst dan de laatst geziene wordt geweigerd (terugspelen).
 *
 * Updates komen in de gewone update-lijst van WordPress, dus automatische
 * updates, "Nu bijwerken" en MainWP werken er zonder meer mee.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adressen van het kanaal, in volgorde van voorkeur. Allebei dezelfde repo
 * (KoenKerkvliet/dp-plugins): Pages is een CDN zonder API-limiet, raw is de
 * terugval als Pages (nog) niet bijgewerkt of bereikbaar is.
 */
function dp_toolbox_dpp_bronnen() {
    return [
        'https://koenkerkvliet.github.io/dp-plugins/',
        'https://raw.githubusercontent.com/KoenKerkvliet/dp-plugins/main/',
    ];
}

/**
 * Publieke sleutels: sleutel-id => Ed25519-sleutel (base64, 32 bytes).
 *
 * Bewust geen filter: wie sleutels mag toevoegen, mag pakketten goedkeuren.
 * Rouleren = nieuwe sleutel erbij zetten, releasen, en pas daarna de oude eruit.
 */
function dp_toolbox_dpp_sleutels() {
    return [
        '4544b8406ffa205f' => 'Th7BBcdKvArV61LQcFcKjHdyXNb+ltDAQh2jOw7TwbY=',
    ];
}

/* ------------------------------------------------------------------ */
/*  Versielijst ophalen en controleren                                  */
/* ------------------------------------------------------------------ */

/**
 * Controleert de envelop { key, signature, data } en geeft de lijst terug.
 *
 * @return array|WP_Error
 */
function dp_toolbox_dpp_verifieer( $body ) {
    $env = json_decode( (string) $body, true );
    if ( ! is_array( $env ) || empty( $env['data'] ) || empty( $env['signature'] ) || empty( $env['key'] ) || ! is_string( $env['data'] ) ) {
        return new WP_Error( 'dp_dpp_vorm', 'geen geldige versielijst' );
    }

    $sleutels = dp_toolbox_dpp_sleutels();
    if ( ! isset( $sleutels[ $env['key'] ] ) ) {
        return new WP_Error( 'dp_dpp_sleutel', 'ondertekend met een onbekende sleutel' );
    }

    if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) && file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
        require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
    }
    if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
        return new WP_Error( 'dp_dpp_sodium', 'deze server kan geen handtekeningen controleren' );
    }

    $handtekening = base64_decode( (string) $env['signature'], true );
    $publiek      = base64_decode( $sleutels[ $env['key'] ], true );
    if ( false === $handtekening || 64 !== strlen( $handtekening ) || false === $publiek || 32 !== strlen( $publiek ) ) {
        return new WP_Error( 'dp_dpp_vorm', 'handtekening heeft een ongeldige vorm' );
    }

    try {
        $geldig = sodium_crypto_sign_verify_detached( $handtekening, $env['data'], $publiek );
    } catch ( \Throwable $e ) {
        $geldig = false;
    }
    if ( ! $geldig ) {
        return new WP_Error( 'dp_dpp_handtekening', 'handtekening klopt niet — lijst genegeerd' );
    }

    $data = json_decode( $env['data'], true );
    if ( ! is_array( $data ) || 1 !== ( $data['schema'] ?? null ) || empty( $data['generated'] ) || ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
        return new WP_Error( 'dp_dpp_vorm', 'onbekend formaat van de versielijst' );
    }

    // Ook een geldig ondertekende lijst mag alleen onze eigen plugins noemen,
    // op het vaste pad. Zo kan hij nooit een andere plugin "bijwerken".
    foreach ( $data['plugins'] as $slug => $p ) {
        $versie = is_array( $p ) ? (string) ( $p['version'] ?? '' ) : '';
        if ( ! is_string( $slug ) || ! preg_match( '/^dp-[a-z0-9-]+$/', $slug )
            || ( $p['file'] ?? '' ) !== $slug . '/' . $slug . '.php'
            || ! preg_match( '/^\d+\.\d+\.\d+$/', $versie )
            || ( $p['path'] ?? '' ) !== 'zips/' . $slug . '/' . $slug . '-' . $versie . '.zip'
            || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $p['sha256'] ?? '' ) ) ) {
            return new WP_Error( 'dp_dpp_vorm', 'ongeldige regel in de versielijst: ' . sanitize_key( (string) $slug ) );
        }
    }

    return $data;
}

/**
 * Haalt de lijst op bij de eerste bron die een geldige, niet-oudere lijst geeft.
 *
 * @return true|WP_Error
 */
function dp_toolbox_dpp_haal_op() {
    $fouten = [];
    $huidig = get_option( 'dp_toolbox_dpp_lijst' );
    $gezien = is_array( $huidig ) ? (int) ( $huidig['data']['generated'] ?? 0 ) : 0;

    foreach ( dp_toolbox_dpp_bronnen() as $bron ) {
        $host     = wp_parse_url( $bron, PHP_URL_HOST );
        $antwoord = wp_remote_get( $bron . 'manifest.json?v=' . time(), [
            'timeout' => 10,
            'headers' => [ 'Accept' => 'application/json', 'Cache-Control' => 'no-cache' ],
        ] );

        if ( is_wp_error( $antwoord ) ) {
            $fouten[] = $host . ': ' . $antwoord->get_error_message();
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code( $antwoord );
        if ( 200 !== $code ) {
            $fouten[] = $host . ': HTTP ' . $code;
            continue;
        }

        $data = dp_toolbox_dpp_verifieer( wp_remote_retrieve_body( $antwoord ) );
        if ( is_wp_error( $data ) ) {
            $fouten[] = $host . ': ' . $data->get_error_message();
            continue;
        }

        // Ouder dan wat we al hadden: een CDN die nog niet ververst is, of een
        // teruggespeelde oude lijst. Probeer de volgende bron; houd de nieuwere.
        if ( (int) $data['generated'] < $gezien ) {
            $fouten[] = $host . ': oudere versielijst dan al bekend';
            continue;
        }

        update_option( 'dp_toolbox_dpp_lijst', [ 'data' => $data, 'bron' => $bron, 'opgehaald' => time() ], false );
        update_option( 'dp_toolbox_dpp_status', [ 'poging' => time(), 'fout' => '' ], false );
        return true;
    }

    update_option( 'dp_toolbox_dpp_status', [ 'poging' => time(), 'fout' => implode( ' · ', $fouten ) ], false );
    return new WP_Error( 'dp_dpp_ophalen', implode( ' · ', $fouten ) );
}

/**
 * De laatst geldige lijst: [ 'data' => [...], 'bron' => url, 'opgehaald' => tijd ].
 *
 * @param int|null $max_leeftijd Null = nooit ophalen (normale paginaweergaven).
 *                               Anders ophalen als de lijst ouder is, met vijf
 *                               minuten pauze na een mislukte of recente poging,
 *                               zodat een onbereikbare bron niet elke request
 *                               tien seconden kost.
 * @param bool     $forceer      Pauze negeren ("Nu controleren").
 * @return array|null
 */
function dp_toolbox_dpp_lijst( $max_leeftijd = null, $forceer = false ) {
    $opgeslagen = get_option( 'dp_toolbox_dpp_lijst' );

    if ( null !== $max_leeftijd || $forceer ) {
        $leeftijd = time() - (int) ( is_array( $opgeslagen ) ? ( $opgeslagen['opgehaald'] ?? 0 ) : 0 );
        $status   = (array) get_option( 'dp_toolbox_dpp_status', [] );
        $pauze    = time() - (int) ( $status['poging'] ?? 0 ) >= 5 * MINUTE_IN_SECONDS;

        if ( $forceer || ( $leeftijd >= (int) $max_leeftijd && $pauze ) ) {
            dp_toolbox_dpp_haal_op();
            $opgeslagen = get_option( 'dp_toolbox_dpp_lijst' );
        }
    }

    return is_array( $opgeslagen ) && ! empty( $opgeslagen['data']['plugins'] ) ? $opgeslagen : null;
}

/**
 * Download-adres van een plugin uit de lijst.
 *
 * @return string|WP_Error
 */
function dp_toolbox_dpp_pakket_url( $slug ) {
    $record = dp_toolbox_dpp_lijst( HOUR_IN_SECONDS );
    if ( ! $record ) {
        $status = (array) get_option( 'dp_toolbox_dpp_status', [] );
        return new WP_Error( 'dp_dpp_geen_lijst', 'De versielijst van DP Plugins is niet beschikbaar' . ( ! empty( $status['fout'] ) ? ': ' . $status['fout'] : '.' ) );
    }
    if ( empty( $record['data']['plugins'][ $slug ] ) ) {
        return new WP_Error( 'dp_dpp_onbekend', $slug . ' staat niet in de versielijst van DP Plugins.' );
    }
    return $record['bron'] . $record['data']['plugins'][ $slug ]['path'];
}

/**
 * Geïnstalleerde versies van de plugins uit de lijst: bestand => versie.
 *
 * Bewust niet via get_plugins(): dit draait bij elke lezing van de
 * update-transient (ook voor de teller in het menu), en we hoeven maar een
 * handvol headers te lezen in plaats van de hele plugins-map te scannen.
 */
function dp_toolbox_dpp_geinstalleerd( $record ) {
    static $cache = [];
    $uit = [];
    foreach ( $record['data']['plugins'] as $p ) {
        $pad = WP_PLUGIN_DIR . '/' . $p['file'];
        if ( ! isset( $cache[ $pad ] ) ) {
            $cache[ $pad ] = file_exists( $pad ) ? (string) get_file_data( $pad, [ 'v' => 'Version' ] )['v'] : false;
        }
        if ( false !== $cache[ $pad ] ) {
            $uit[ $p['file'] ] = $cache[ $pad ];
        }
    }
    return $uit;
}

/* ------------------------------------------------------------------ */
/*  Aanhaken op de updates van WordPress                                */
/* ------------------------------------------------------------------ */

function dp_toolbox_dpp_update_item( $slug, $p, $bron ) {
    $icoon = DP_TOOLBOX_URL . 'assets/dp-icon.png';
    return (object) [
        'id'            => 'designpixels/' . $slug,
        'slug'          => $slug,
        'plugin'        => $p['file'],
        'new_version'   => $p['version'],
        'url'           => 'https://designpixels.nl/',
        'package'       => $bron . $p['path'],
        'icons'         => [ '1x' => $icoon, '2x' => $icoon, 'default' => $icoon ],
        'banners'       => [],
        'banners_rtl'   => [],
        'tested'        => '',
        'requires'      => (string) ( $p['requires_wp'] ?? '' ),
        'requires_php'  => (string) ( $p['requires_php'] ?? '' ),
        'compatibility' => new stdClass(),
    ];
}

/**
 * Zet onze plugins in de update-transient, bij elke lezing.
 *
 * Bij het lezen en niet bij het opslaan, op prioriteit 999: zo winnen we van
 * Git Updater, die op sites met een oudere DP-plugin ook nog een (andere) versie
 * voor dezelfde plugin kan melden. Deze lijst is de enige waarheid.
 */
add_filter( 'site_transient_update_plugins', function ( $transient ) {
    $record = dp_toolbox_dpp_lijst();

    // Nog nooit opgehaald (net geïnstalleerd): één poging, alleen in admin/cron.
    if ( ! $record && ( is_admin() || wp_doing_cron() ) ) {
        $record = dp_toolbox_dpp_lijst( 0 );
    }
    if ( ! $record ) {
        return $transient;
    }

    $geinstalleerd = dp_toolbox_dpp_geinstalleerd( $record );
    if ( ! $geinstalleerd ) {
        return $transient;
    }

    if ( ! is_object( $transient ) ) {
        $transient = new stdClass();
    }
    if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
        $transient->response = [];
    }
    if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
        $transient->no_update = [];
    }

    foreach ( $record['data']['plugins'] as $slug => $p ) {
        if ( ! isset( $geinstalleerd[ $p['file'] ] ) ) {
            continue;
        }
        $item = dp_toolbox_dpp_update_item( $slug, $p, $record['bron'] );

        if ( version_compare( $p['version'], $geinstalleerd[ $p['file'] ], '>' ) ) {
            $transient->response[ $p['file'] ] = $item;
            unset( $transient->no_update[ $p['file'] ] );
        } else {
            // In no_update, zodat WordPress de schakelaar voor automatisch
            // bijwerken ook bij een actuele plugin toont.
            unset( $transient->response[ $p['file'] ] );
            $transient->no_update[ $p['file'] ] = $item;
        }
    }

    return $transient;
}, 999 );

/**
 * Ververs de lijst wanneer WordPress zelf naar updates kijkt (cron 2× per dag,
 * of in wp-admin). De transient wordt per check twee keer opgeslagen; de
 * leeftijdsgrens zorgt dat we maar één keer ophalen.
 */
add_filter( 'pre_set_site_transient_update_plugins', function ( $waarde ) {
    dp_toolbox_dpp_lijst( 30 * MINUTE_IN_SECONDS );
    return $waarde;
}, 5 );

/**
 * Elke download van ons kanaal loopt hierlangs, ongeacht de route: onze pagina,
 * "Nu bijwerken", automatische updates, MainWP of de Plugin Installer-module.
 * We downloaden zelf en geven het bestand alleen door als de sha256 klopt.
 *
 * LANDMIJN — daarom prioriteit PHP_INT_MAX: Git Updater hangt een closure aan
 * dit filter die ALTIJD `false` teruggeeft, ook als een eerder filter al een
 * bestand leverde. Op prioriteit 10 gooide hij ons gecontroleerde bestand weg
 * en downloadde WordPress het pakket daarna opnieuw, zonder controle. Als
 * laatste draaien voorkomt dat; en heeft iemand vóór ons toch al een bestand
 * geleverd voor een adres van ons kanaal, dan controleren we dat bestand.
 */
add_filter( 'upgrader_pre_download', function ( $reply, $package, $upgrader = null, $hook_extra = [] ) {
    if ( is_wp_error( $reply ) || ! is_string( $package ) ) {
        return $reply;
    }

    $pad = null;
    foreach ( dp_toolbox_dpp_bronnen() as $bron ) {
        if ( 0 === strpos( $package, $bron ) ) {
            $pad = strtok( substr( $package, strlen( $bron ) ), '?#' );
            break;
        }
    }
    if ( null === $pad ) {
        return $reply; // Niet van ons kanaal: niet onze zaak.
    }

    $zoek = function ( $record ) use ( $pad ) {
        foreach ( (array) ( $record['data']['plugins'] ?? [] ) as $p ) {
            if ( $p['path'] === $pad ) {
                return $p;
            }
        }
        return null;
    };

    $item = $zoek( dp_toolbox_dpp_lijst() );
    if ( ! $item ) {
        // Mogelijk net een nieuwe versie gepubliceerd: één keer vers ophalen.
        $item = $zoek( dp_toolbox_dpp_lijst( 0, true ) );
    }
    if ( ! $item ) {
        return new WP_Error( 'dp_dpp_onbekend', 'Dit pakket staat niet in de ondertekende versielijst van Design Pixels. Installatie afgebroken.' );
    }

    if ( is_string( $reply ) && '' !== $reply && is_file( $reply ) ) {
        $tmp = $reply; // Al door een ander filter gedownload: dan dát bestand controleren.
    } else {
        if ( $upgrader && isset( $upgrader->skin ) && isset( $upgrader->strings['downloading_package'] ) ) {
            $upgrader->skin->feedback( 'downloading_package', $package );
        }

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url( $package, 300, false );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }
    }

    if ( ! hash_equals( $item['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) {
        wp_delete_file( $tmp );
        return new WP_Error( 'dp_dpp_hash', 'Het gedownloade pakket wijkt af van de ondertekende versielijst (controlegetal klopt niet). Installatie afgebroken.' );
    }

    return $tmp;
}, PHP_INT_MAX, 4 );

/**
 * "Details bekijken" in het pluginoverzicht — anders vraagt WordPress het aan
 * wordpress.org en krijg je een foutmelding.
 */
add_filter( 'plugins_api', function ( $res, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
        return $res;
    }
    $record = dp_toolbox_dpp_lijst();
    if ( ! $record || empty( $record['data']['plugins'][ $args->slug ] ) ) {
        return $res;
    }
    $p     = $record['data']['plugins'][ $args->slug ];
    $icoon = DP_TOOLBOX_URL . 'assets/dp-icon.png';

    return (object) [
        'name'          => $p['name'],
        'slug'          => $args->slug,
        'version'       => $p['version'],
        'author'        => '<a href="https://designpixels.nl/">Design Pixels</a>',
        'homepage'      => 'https://designpixels.nl/',
        'requires'      => (string) ( $p['requires_wp'] ?? '' ),
        'requires_php'  => (string) ( $p['requires_php'] ?? '' ),
        'last_updated'  => gmdate( 'Y-m-d H:i:s', (int) ( $p['released'] ?? $record['data']['generated'] ) ),
        'download_link' => $record['bron'] . $p['path'],
        'sections'      => [
            'description' => '<p>' . esc_html( $p['description'] ?? '' ) . '</p>'
                . '<p>Deze plugin wordt bijgewerkt via het eigen update-kanaal van Design Pixels (DP Toolbox → DP Plugins). Elk pakket is gecontroleerd tegen een ondertekende versielijst.</p>',
        ],
        'icons'            => [ '1x' => $icoon, '2x' => $icoon ],
        'banners'          => [],
        'requires_plugins' => [], // install-scherm van WP 6.5+ leest dit veld
    ];
}, 999, 3 );

/* ------------------------------------------------------------------ */
/*  Menu                                                               */
/* ------------------------------------------------------------------ */

/** Aantal DP-plugins met een nieuwere versie in de lijst. */
function dp_toolbox_dpp_aantal_updates() {
    $record = dp_toolbox_dpp_lijst();
    if ( ! $record ) {
        return 0;
    }
    $n = 0;
    foreach ( dp_toolbox_dpp_geinstalleerd( $record ) as $file => $versie ) {
        foreach ( $record['data']['plugins'] as $p ) {
            if ( $p['file'] === $file && version_compare( $p['version'], $versie, '>' ) ) {
                $n++;
            }
        }
    }
    return $n;
}

add_action( 'admin_menu', function () {
    global $submenu;
    if ( ! dp_toolbox_current_user_has_access() || ! current_user_can( 'install_plugins' ) ) {
        return;
    }
    $n     = dp_toolbox_dpp_aantal_updates();
    $label = 'DP Plugins' . ( $n ? ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>' : '' );
    // Een submenu-item met een .php-adres linkt WordPress rechtstreeks: naar het tabblad.
    $submenu['dp-toolbox'][] = [ $label, 'install_plugins', 'admin.php?page=dp-toolbox&tab=plugins', 'DP Plugins' ];
}, 20 );

// Het juiste submenu-item oplichten op het tabblad (anders licht "Modules" op).
add_filter( 'submenu_file', function ( $submenu_file, $parent_file ) {
    if ( 'dp-toolbox' === $parent_file && 'plugins' === ( $_GET['tab'] ?? '' ) ) {
        return 'admin.php?page=dp-toolbox&tab=plugins';
    }
    return $submenu_file;
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  Acties (AJAX)                                                      */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_dpp', function () {
    check_ajax_referer( 'dp_toolbox_dpp', 'nonce' );
    if ( ! dp_toolbox_current_user_has_access() ) {
        wp_send_json_error( [ 'message' => 'Geen toegang.' ], 403 );
    }

    $doe = sanitize_key( wp_unslash( $_POST['doe'] ?? '' ) );

    if ( 'vernieuwen' === $doe ) {
        $r = dp_toolbox_dpp_haal_op();
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( [ 'message' => 'Ophalen mislukt: ' . $r->get_error_message() ] );
        }
        wp_send_json_success();
    }

    $slug   = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
    $record = dp_toolbox_dpp_lijst();
    $p      = $record['data']['plugins'][ $slug ] ?? null;
    if ( ! $p ) {
        wp_send_json_error( [ 'message' => 'Onbekende plugin.' ] );
    }

    if ( 'automatisch' === $doe ) {
        if ( ! current_user_can( 'update_plugins' ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
            wp_send_json_error( [ 'message' => 'Automatisch bijwerken is op deze site niet beschikbaar.' ] );
        }
        $lijst = (array) get_site_option( 'auto_update_plugins', [] );
        $lijst = array_values( array_diff( $lijst, [ $p['file'] ] ) );
        if ( ! empty( $_POST['aan'] ) ) {
            $lijst[] = $p['file'];
        }
        update_site_option( 'auto_update_plugins', $lijst );
        wp_send_json_success();
    }

    wp_send_json_error( [ 'message' => 'Onbekende actie.' ] );
} );

/*
 * LANDMIJN — installeren, bijwerken en activeren gaan bewust NIET via AJAX.
 *
 * In 2.64.0 draaide de knop Plugin_Upgrader in een eigen AJAX-verzoek. Git
 * Updater roept tijdens elke update `check_ajax_referer( 'updates' )` aan
 * (GU_Trait::get_repo_slugs, zodra er een `action` in $_POST staat) — dat is de
 * nonce van WordPress' eigen updateknoppen. Die hadden wij niet, dus stopte het
 * verzoek met "-1", precies tussen het deactiveren van de plugin en het
 * plaatsen van de nieuwe bestanden: DP Toolbox bleef uitgeschakeld achter.
 *
 * Daarom linken de knoppen naar update.php en plugins.php. Dat is de route
 * waarop elke plugin rekent, en WordPress heractiveert een bijgewerkte actieve
 * plugin daar zelf — ook DP Toolbox. Onze sha256-controle zit in
 * upgrader_pre_download en werkt daar net zo.
 */

/** Adres van WordPress' eigen scherm voor installeren / bijwerken / activeren. */
function dp_toolbox_dpp_actie_url( $doe, $slug, $file ) {
    switch ( $doe ) {
        case 'installeren':
            return wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug );
        case 'bijwerken':
            return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
        case 'activeren':
            return wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file );
    }
    return '';
}

/** "Terug naar DP Plugins" onder het resultaat van WordPress' update-scherm. */
function dp_toolbox_dpp_terug_link( $acties ) {
    if ( dp_toolbox_current_user_has_access() ) {
        $acties['dp_plugins'] = '<a href="' . esc_url( admin_url( 'admin.php?page=dp-toolbox&tab=plugins' ) ) . '" target="_parent">Terug naar DP Plugins</a>';
    }
    return $acties;
}

add_filter( 'update_plugin_complete_actions', function ( $acties, $plugin ) {
    $record = dp_toolbox_dpp_lijst();
    foreach ( (array) ( $record['data']['plugins'] ?? [] ) as $p ) {
        if ( $p['file'] === $plugin ) {
            return dp_toolbox_dpp_terug_link( $acties );
        }
    }
    return $acties;
}, 10, 2 );

add_filter( 'install_plugin_complete_actions', function ( $acties, $api, $plugin_file ) {
    $record = dp_toolbox_dpp_lijst();
    if ( is_object( $api ) && ! empty( $api->slug ) && ! empty( $record['data']['plugins'][ $api->slug ] ) ) {
        return dp_toolbox_dpp_terug_link( $acties );
    }
    return $acties;
}, 10, 3 );

/* ------------------------------------------------------------------ */
/*  Tabblad "DP Plugins"                                                */
/* ------------------------------------------------------------------ */

function dp_toolbox_render_plugins_tab() {
    if ( ! current_user_can( 'install_plugins' ) ) {
        echo '<p>Je hebt geen rechten om plugins te installeren op deze site.</p>';
        return;
    }

    // Wie de pagina opent wil de actuele stand zien: ophalen als ouder dan een uur.
    $record = dp_toolbox_dpp_lijst( HOUR_IN_SECONDS );
    $status = (array) get_option( 'dp_toolbox_dpp_status', [] );
    $nonce  = wp_create_nonce( 'dp_toolbox_dpp' );

    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $auto_kan = current_user_can( 'update_plugins' ) && wp_is_auto_update_enabled_for_type( 'plugin' );
    $auto     = (array) get_site_option( 'auto_update_plugins', [] );
    ?>
    <style>
        .dp-dpp-intro { margin: 0 0 16px; color: #50575e; font-size: 13px; max-width: 760px; }
        .dp-dpp-status {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 10px 16px; margin-bottom: 16px; font-size: 13px;
        }
        .dp-dpp-status .dashicons { font-size: 18px; width: 18px; height: 18px; }
        .dp-dpp-status.is-ok .dashicons { color: #1a7f37; }
        .dp-dpp-status.is-fout .dashicons { color: #d63638; }
        .dp-dpp-status .dp-dpp-grow { flex: 1; min-width: 200px; }
        .dp-dpp-status small { color: #787c82; }

        .dp-dpp-lijst { display: flex; flex-direction: column; gap: 8px; }
        .dp-dpp-kaart {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
            background: #fff; border: 1px solid #e0e0e0; border-left: 3px solid #dcdcde;
            border-radius: 8px; padding: 14px 18px;
        }
        .dp-dpp-kaart.is-active   { border-left-color: #281E5D; }
        .dp-dpp-kaart.is-inactive { border-left-color: #c48a00; }
        .dp-dpp-kaart.is-update   { border-left-color: #d63638; }
        .dp-dpp-info { flex: 1; min-width: 220px; }
        .dp-dpp-info h3 { margin: 0; font-size: 14px; font-weight: 600; color: #1d2327; }
        .dp-dpp-info h3 .dp-dpp-versie { color: #8c8f94; font-size: 11px; font-weight: 400; margin-left: 6px; }
        .dp-dpp-info h3 .dp-dpp-nieuw { color: #d63638; font-size: 11px; font-weight: 600; margin-left: 4px; }
        .dp-dpp-info p { margin: 3px 0 0; color: #787c82; font-size: 12px; line-height: 1.5; }
        .dp-dpp-fout { color: #d63638 !important; }

        .dp-dpp-acties { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .dp-dpp-badge {
            font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
            padding: 4px 9px; border-radius: 4px; white-space: nowrap;
        }
        .dp-dpp-badge.active   { color: #281E5D; background: #eee8ff; }
        .dp-dpp-badge.inactive { color: #996800; background: #fff4d6; }
        .dp-dpp-badge.missing  { color: #50575e; background: #f0f0f1; }
        .dp-dpp-knop {
            border-radius: 6px; padding: 5px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
            border: 1px solid #281E5D; background: #281E5D; color: #fff; line-height: 1.6;
            text-decoration: none; display: inline-block;
        }
        .dp-dpp-knop:hover, .dp-dpp-knop:focus { background: #4a3a8a; border-color: #4a3a8a; color: #fff; }
        .dp-dpp-knop.is-licht { background: #fff; color: #281E5D; border-color: #c3c4c7; }
        .dp-dpp-knop.is-licht:hover, .dp-dpp-knop.is-licht:focus { background: #fff; color: #281E5D; border-color: #281E5D; }
        .dp-dpp-knop[disabled] { opacity: .5; cursor: wait; }
        .dp-dpp-auto { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #50575e; cursor: pointer; }
    </style>

    <div class="dp-section-header">
        <span class="dashicons dashicons-update"></span>
        <h2>Design Pixels-plugins</h2>
    </div>
    <p class="dp-dpp-intro">
        Installeer en werk de eigen plugins bij vanuit één plek. De versielijst is ondertekend door Design Pixels;
        een pakket wordt alleen geïnstalleerd als het exact overeenkomt met die lijst. Updates verschijnen ook gewoon
        onder Plugins en Dashboard → Updates.
        <?php if ( current_user_can( 'update_plugins' ) && ! $auto_kan ) : ?>
            <br><em>Automatisch bijwerken staat op deze site uit (een andere plugin of instelling zet het filter
            <code>automatic_updater_disabled</code>); bijwerken gaat hier dus met de hand of via MainWP.</em>
        <?php endif; ?>
    </p>

    <div class="dp-dpp-status <?php echo $record && empty( $status['fout'] ) ? 'is-ok' : 'is-fout'; ?>">
        <span class="dashicons <?php echo $record && empty( $status['fout'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
        <span class="dp-dpp-grow">
            <?php if ( $record ) : ?>
                Versielijst gecontroleerd — opgehaald <?php echo esc_html( wp_date( 'j M Y, H:i', (int) $record['opgehaald'] ) ); ?>
                <small>via <?php echo esc_html( wp_parse_url( $record['bron'], PHP_URL_HOST ) ); ?></small>
                <?php if ( ! empty( $status['fout'] ) ) : ?>
                    <br><small class="dp-dpp-fout">Laatste poging mislukt: <?php echo esc_html( $status['fout'] ); ?></small>
                <?php endif; ?>
            <?php else : ?>
                De versielijst is nog niet beschikbaar<?php echo ! empty( $status['fout'] ) ? ': ' . esc_html( $status['fout'] ) : '.'; ?>
            <?php endif; ?>
        </span>
        <button type="button" class="dp-dpp-knop is-licht" data-doe="vernieuwen">Nu controleren</button>
    </div>

    <?php if ( $record ) :
        $plugins = $record['data']['plugins'];
        uasort( $plugins, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        ?>
        <div class="dp-dpp-lijst">
            <?php foreach ( $plugins as $slug => $p ) :
                $pad       = WP_PLUGIN_DIR . '/' . $p['file'];
                $versie    = file_exists( $pad ) ? (string) get_file_data( $pad, [ 'v' => 'Version' ] )['v'] : '';
                $staat     = '' === $versie ? 'missing' : ( is_plugin_active( $p['file'] ) ? 'active' : 'inactive' );
                $update    = '' !== $versie && version_compare( $p['version'], $versie, '>' );
                $php_nodig = (string) ( $p['requires_php'] ?? '' );
                $php_te_oud = '' !== $php_nodig && version_compare( PHP_VERSION, $php_nodig, '<' );
                $klasse    = $update ? 'is-update' : 'is-' . $staat;
                ?>
                <div class="dp-dpp-kaart <?php echo esc_attr( $klasse ); ?>" data-slug="<?php echo esc_attr( $slug ); ?>">
                    <div class="dp-dpp-info">
                        <h3>
                            <?php echo esc_html( $p['name'] ); ?>
                            <?php if ( '' !== $versie ) : ?>
                                <span class="dp-dpp-versie">v<?php echo esc_html( $versie ); ?></span>
                            <?php else : ?>
                                <span class="dp-dpp-versie">v<?php echo esc_html( $p['version'] ); ?> beschikbaar</span>
                            <?php endif; ?>
                            <?php if ( $update ) : ?>
                                <span class="dp-dpp-nieuw">→ v<?php echo esc_html( $p['version'] ); ?></span>
                            <?php endif; ?>
                        </h3>
                        <p><?php echo esc_html( $p['description'] ?? '' ); ?></p>
                        <?php if ( $php_te_oud ) : ?>
                            <p class="dp-dpp-fout">Vereist PHP <?php echo esc_html( $php_nodig ); ?>; deze server draait <?php echo esc_html( PHP_VERSION ); ?>.</p>
                        <?php endif; ?>
                        <p class="dp-dpp-fout" data-melding hidden></p>
                    </div>
                    <div class="dp-dpp-acties">
                        <?php if ( 'missing' !== $staat && $auto_kan ) : ?>
                            <label class="dp-dpp-auto">
                                <input type="checkbox" data-doe="automatisch" <?php checked( in_array( $p['file'], $auto, true ) ); ?>>
                                Automatisch bijwerken
                            </label>
                        <?php endif; ?>
                        <span class="dp-dpp-badge <?php echo esc_attr( $staat ); ?>">
                            <?php echo 'active' === $staat ? 'Actief' : ( 'inactive' === $staat ? 'Geïnstalleerd' : 'Niet geïnstalleerd' ); ?>
                        </span>
                        <?php if ( 'missing' === $staat && ! $php_te_oud ) : ?>
                            <a class="dp-dpp-knop" href="<?php echo esc_url( dp_toolbox_dpp_actie_url( 'installeren', $slug, $p['file'] ) ); ?>">Installeren</a>
                        <?php endif; ?>
                        <?php if ( $update && ! $php_te_oud && current_user_can( 'update_plugins' ) ) : ?>
                            <a class="dp-dpp-knop" href="<?php echo esc_url( dp_toolbox_dpp_actie_url( 'bijwerken', $slug, $p['file'] ) ); ?>">Bijwerken</a>
                        <?php endif; ?>
                        <?php if ( 'inactive' === $staat && current_user_can( 'activate_plugins' ) ) : ?>
                            <a class="dp-dpp-knop is-licht" href="<?php echo esc_url( dp_toolbox_dpp_actie_url( 'activeren', $slug, $p['file'] ) ); ?>">Activeren</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
    (function () {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
        var bezig   = { vernieuwen: 'Controleren…' };

        function stuur(doe, slug, extra) {
            var body = new FormData();
            body.append('action', 'dp_toolbox_dpp');
            body.append('nonce', nonce);
            body.append('doe', doe);
            if (slug) body.append('slug', slug);
            if (extra) Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
            return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                    var j = null;
                    try { j = JSON.parse(t); } catch (e) {}
                    // "-1" of "0" is ook geldige JSON: een nonce- of rechtencontrole die het verzoek afbrak.
                    if (!j || typeof j !== 'object') {
                        return { success: false, data: { message: 'De server brak het verzoek af (' + String(t).slice(0, 40) + '). Herlaad de pagina en probeer het opnieuw.' } };
                    }
                    return j;
                });
        }

        function meld(kaart, tekst) {
            var el = kaart && kaart.querySelector('[data-melding]');
            if (el) { el.textContent = tekst; el.hidden = false; } else { alert(tekst); }
        }

        document.addEventListener('click', function (e) {
            var knop = e.target.closest('button.dp-dpp-knop[data-doe]');
            if (!knop) return;
            var doe   = knop.getAttribute('data-doe');
            var kaart = knop.closest('.dp-dpp-kaart');
            document.querySelectorAll('button.dp-dpp-knop').forEach(function (b) { b.disabled = true; });
            knop.textContent = bezig[doe] || 'Bezig…';
            stuur(doe, kaart ? kaart.getAttribute('data-slug') : '').then(function (j) {
                if (j.success) { location.reload(); return; }
                meld(kaart, (j.data && j.data.message) || 'Onbekende fout.');
                document.querySelectorAll('button.dp-dpp-knop').forEach(function (b) { b.disabled = false; });
                knop.textContent = 'Opnieuw proberen';
            });
        });

        document.addEventListener('change', function (e) {
            var vak = e.target.closest('input[data-doe="automatisch"]');
            if (!vak) return;
            var kaart = vak.closest('.dp-dpp-kaart');
            vak.disabled = true;
            stuur('automatisch', kaart.getAttribute('data-slug'), { aan: vak.checked ? '1' : '' }).then(function (j) {
                vak.disabled = false;
                if (!j.success) { vak.checked = !vak.checked; meld(kaart, (j.data && j.data.message) || 'Onbekende fout.'); }
            });
        });
    })();
    </script>
    <?php
}
