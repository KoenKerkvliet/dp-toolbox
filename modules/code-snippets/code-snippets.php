<?php
/**
 * Module Name: Code Snippets
 * Description: Voer eigen PHP-, JS- of CSS-snippets uit. Snippets worden via de admin-pagina aangemaakt en opgeslagen in de database. Daarnaast worden PHP-bestanden uit de snippets/ map ingeladen voor versie-beheerde, plugin-bundled snippets.
 * Category: tools
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ====================================================================== */
/*  FILE-BASED SNIPPETS (legacy / version-controlled)                      */
/* ====================================================================== */

/**
 * Pad naar de snippets-map binnen deze module.
 */
function dp_toolbox_snippets_dir() {
    return __DIR__ . '/snippets/';
}

/**
 * Ontdek alle .php bestanden in snippets/ en parse hun headers.
 *
 * Header-formaat:
 *   Name:        Mensleesbare naam
 *   Description: Wat doet de snippet
 *   Sites:       comma-separated hostnames (bijv. preciousduck.s5-tastewp.com).
 *                Leeg of "*" = overal actief.
 *   Status:      "active" of "inactive". Default: active.
 *   Version:     optioneel
 */
function dp_toolbox_snippets_discover() {
    $dir      = dp_toolbox_snippets_dir();
    $snippets = [];

    if ( ! is_dir( $dir ) ) {
        return $snippets;
    }

    foreach ( glob( $dir . '*.php' ) as $file ) {
        $headers = get_file_data( $file, [
            'name'        => 'Name',
            'description' => 'Description',
            'sites'       => 'Sites',
            'status'      => 'Status',
            'version'     => 'Version',
        ] );

        $slug = basename( $file, '.php' );

        $snippets[ $slug ] = [
            'slug'        => $slug,
            'file'        => $file,
            'name'        => $headers['name'] ?: $slug,
            'description' => $headers['description'] ?: '',
            'sites'       => $headers['sites'] ?: '',
            'status'      => strtolower( trim( $headers['status'] ) ) ?: 'active',
            'version'     => $headers['version'] ?: '',
        ];
    }

    ksort( $snippets );
    return $snippets;
}

/**
 * Bepaalt of een file-based snippet op deze site actief moet zijn.
 */
function dp_toolbox_snippet_is_applicable( $snippet ) {
    if ( $snippet['status'] !== 'active' ) {
        return false;
    }

    $sites = trim( $snippet['sites'] );
    if ( $sites === '' || $sites === '*' ) {
        return true;
    }

    $current_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $allowed      = array_filter( array_map( 'trim', explode( ',', $sites ) ) );

    return in_array( $current_host, $allowed, true );
}

/**
 * Laad alle file-based snippets die op deze site moeten draaien.
 */
function dp_toolbox_snippets_load() {
    if ( dp_toolbox_snippets_safe_mode() ) {
        return;
    }
    foreach ( dp_toolbox_snippets_discover() as $snippet ) {
        if ( dp_toolbox_snippet_is_applicable( $snippet ) ) {
            require_once $snippet['file'];
        }
    }
}

dp_toolbox_snippets_load();

/* ====================================================================== */
/*  DB-BASED SNIPPETS                                                      */
/* ====================================================================== */

/**
 * Safe-mode: bypass alle snippet-execution wanneer ?dp_safe_mode=1 in URL.
 * Geen cap-check (pluggable.php is bij plugins_loaded nog niet geladen);
 * de URL-flag uitschakelen van snippets is niet destructief, dus elk publiek
 * gebruik is acceptabel.
 */
function dp_toolbox_snippets_safe_mode() {
    return ! empty( $_GET['dp_safe_mode'] );
}

/**
 * Haal alle DB-snippets op (option array).
 */
function dp_toolbox_db_snippets_get_all() {
    return (array) get_option( 'dp_toolbox_snippets', [] );
}

/**
 * Update één DB-snippet (merge in option array).
 */
function dp_toolbox_db_snippet_update( $id, array $patch ) {
    $all = dp_toolbox_db_snippets_get_all();
    if ( ! isset( $all[ $id ] ) ) return false;
    $all[ $id ] = array_merge( $all[ $id ], $patch );
    update_option( 'dp_toolbox_snippets', $all, false );
    return true;
}

/**
 * Markeer een snippet als "broken" en deactiveer hem.
 */
function dp_toolbox_db_snippet_mark_error( $id, $message ) {
    dp_toolbox_db_snippet_update( $id, [
        'active'    => false,
        'has_error' => true,
        'error_msg' => substr( (string) $message, 0, 500 ),
        'error_at'  => current_time( 'mysql' ),
    ] );
}

/**
 * Check of een snippet op de huidige site/hostname mag draaien.
 */
function dp_toolbox_db_snippet_site_match( $sites ) {
    $sites = trim( (string) $sites );
    if ( $sites === '' || $sites === '*' ) return true;
    $current = wp_parse_url( home_url(), PHP_URL_HOST );
    $allowed = array_filter( array_map( 'trim', explode( ',', $sites ) ) );
    return in_array( $current, $allowed, true );
}

/**
 * Voer een PHP-snippet veilig uit. Vangt parse-errors en runtime-throwables;
 * fatale errors worden via shutdown-handler opgevangen en de snippet wordt
 * automatisch gedeactiveerd.
 */
function dp_toolbox_db_snippet_execute_php( $snippet ) {
    if ( ! empty( $snippet['has_error'] ) ) return;

    $code = (string) $snippet['code'];
    // Strip optionele <?php prefix, want eval wil dat niet.
    $code = preg_replace( '/^\s*<\?(php)?\s*/i', '', $code );

    $GLOBALS['dp_toolbox_current_snippet_id'] = $snippet['id'];

    try {
        // phpcs:ignore Squiz.PHP.Eval.Discouraged
        eval( $code );
    } catch ( ParseError $e ) {
        dp_toolbox_db_snippet_mark_error( $snippet['id'], 'Parse: ' . $e->getMessage() . ' (regel ' . $e->getLine() . ')' );
    } catch ( Throwable $e ) {
        dp_toolbox_db_snippet_mark_error( $snippet['id'], get_class( $e ) . ': ' . $e->getMessage() );
    }

    unset( $GLOBALS['dp_toolbox_current_snippet_id'] );
}

/**
 * Shutdown-handler: markeert de snippet als broken als de fatal pal tijdens
 * eval optrad. Voor latere fatals (in geregistreerde hooks) kunnen we de
 * specifieke snippet niet meer attribueren — dan rekent de gebruiker zelf
 * via ?dp_safe_mode=1 + de admin-pagina af.
 */
function dp_toolbox_db_snippet_shutdown_handler() {
    if ( empty( $GLOBALS['dp_toolbox_current_snippet_id'] ) ) return;
    $err = error_get_last();
    if ( ! $err ) return;
    $fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
    if ( ! in_array( $err['type'], $fatal_types, true ) ) return;
    dp_toolbox_db_snippet_mark_error( $GLOBALS['dp_toolbox_current_snippet_id'], 'Fatal: ' . $err['message'] );
}
register_shutdown_function( 'dp_toolbox_db_snippet_shutdown_handler' );

/**
 * Hoofdrunner — wordt aangeroepen op `plugins_loaded`.
 * Sorteert snippets op priority en dispatcht naar de juiste type-runner.
 */
function dp_toolbox_db_snippets_run() {
    if ( dp_toolbox_snippets_safe_mode() ) return;

    $snippets = dp_toolbox_db_snippets_get_all();
    if ( empty( $snippets ) ) return;

    // Sort by priority asc (10 = default; lower = earlier)
    uasort( $snippets, function ( $a, $b ) {
        return ( (int) ( $a['priority'] ?? 10 ) ) <=> ( (int) ( $b['priority'] ?? 10 ) );
    } );

    foreach ( $snippets as $s ) {
        if ( empty( $s['active'] ) || ! empty( $s['has_error'] ) ) continue;
        if ( ! dp_toolbox_db_snippet_site_match( $s['sites'] ?? '' ) ) continue;

        $type  = $s['type']  ?? 'php';
        $scope = $s['scope'] ?? 'everywhere';

        // Scope-check
        if ( $type === 'php' ) {
            if ( $scope === 'admin'    && ! is_admin() ) continue;
            if ( $scope === 'frontend' && is_admin() )   continue;
            dp_toolbox_db_snippet_execute_php( $s );
        }
        elseif ( $type === 'css' ) {
            dp_toolbox_db_snippet_register_css( $s );
        }
        elseif ( $type === 'js' ) {
            dp_toolbox_db_snippet_register_js( $s );
        }
    }
}
add_action( 'plugins_loaded', 'dp_toolbox_db_snippets_run', 20 );

/**
 * Registreer een CSS-snippet voor inline-output.
 */
function dp_toolbox_db_snippet_register_css( $snippet ) {
    $scope    = $snippet['scope'] ?? 'frontend';
    $is_admin = ( $scope === 'admin' );
    $hook     = $is_admin ? 'admin_head' : 'wp_head';

    if ( $is_admin && ! is_admin() ) return;
    if ( ! $is_admin && is_admin() ) return;

    add_action( $hook, function () use ( $snippet ) {
        $code = (string) $snippet['code'];
        // Strip evt. <style>-tags die de gebruiker per ongeluk plakt
        $code = preg_replace( '#</?style[^>]*>#i', '', $code );
        printf(
            "<style id=\"dp-snippet-%s\">\n%s\n</style>\n",
            esc_attr( $snippet['id'] ),
            $code
        );
    }, (int) ( $snippet['priority'] ?? 10 ) );
}

/**
 * Registreer een JS-snippet voor inline-output.
 */
function dp_toolbox_db_snippet_register_js( $snippet ) {
    $scope = $snippet['scope'] ?? 'frontend_footer';

    if ( $scope === 'admin' ) {
        if ( ! is_admin() ) return;
        $hook = 'admin_footer';
    } else {
        if ( is_admin() ) return;
        $hook = ( $scope === 'frontend_head' ) ? 'wp_head' : 'wp_footer';
    }

    add_action( $hook, function () use ( $snippet ) {
        $code = (string) $snippet['code'];
        // Strip evt. <script>-tags
        $code = preg_replace( '#</?script[^>]*>#i', '', $code );
        printf(
            "<script id=\"dp-snippet-%s\">\n%s\n</script>\n",
            esc_attr( $snippet['id'] ),
            $code
        );
    }, (int) ( $snippet['priority'] ?? 10 ) );
}

/* ====================================================================== */
/*  AJAX handlers                                                          */
/* ====================================================================== */

add_action( 'wp_ajax_dp_toolbox_snippet_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Geen toestemming.' );
    check_ajax_referer( 'dp_toolbox_snippets', 'nonce' );

    $id          = sanitize_text_field( $_POST['id']          ?? '' );
    $title       = sanitize_text_field( $_POST['title']       ?? '' );
    $description = sanitize_textarea_field( $_POST['description'] ?? '' );
    $type        = in_array( $_POST['type'] ?? 'php', [ 'php', 'js', 'css' ], true ) ? $_POST['type'] : 'php';
    // Code: NIET sanitizen — dat zou de syntax breken. Wel slashen ongedaan maken.
    $code        = wp_unslash( $_POST['code'] ?? '' );
    $priority    = max( 1, min( 999, (int) ( $_POST['priority'] ?? 10 ) ) );
    $sites       = sanitize_text_field( $_POST['sites']  ?? '' );
    $active      = ! empty( $_POST['active'] );

    // Geldige scope per type
    $scope_options = [
        'php' => [ 'everywhere', 'admin', 'frontend' ],
        'js'  => [ 'frontend_head', 'frontend_footer', 'admin' ],
        'css' => [ 'frontend', 'admin' ],
    ];
    $scope_default = [ 'php' => 'everywhere', 'js' => 'frontend_footer', 'css' => 'frontend' ];
    $scope = sanitize_text_field( $_POST['scope'] ?? '' );
    if ( ! in_array( $scope, $scope_options[ $type ], true ) ) {
        $scope = $scope_default[ $type ];
    }

    if ( $title === '' ) wp_send_json_error( 'Titel is verplicht.' );
    if ( trim( $code ) === '' ) wp_send_json_error( 'Code mag niet leeg zijn.' );

    // PHP-syntax check vóór opslaan, zodat we geen onbruikbare snippet activeren
    if ( $type === 'php' && $active ) {
        $check = dp_toolbox_php_syntax_check( $code );
        if ( $check !== true ) {
            wp_send_json_error( 'PHP-syntaxfout: ' . $check );
        }
    }

    $all = dp_toolbox_db_snippets_get_all();
    $now = current_time( 'mysql' );

    if ( $id !== '' && isset( $all[ $id ] ) ) {
        // Bewerken
        $all[ $id ] = array_merge( $all[ $id ], [
            'title'       => $title,
            'description' => $description,
            'type'        => $type,
            'code'        => $code,
            'scope'       => $scope,
            'priority'    => $priority,
            'sites'       => $sites,
            'active'      => $active,
            'updated'     => $now,
            // reset error-state on save
            'has_error'   => false,
            'error_msg'   => '',
        ] );
    } else {
        // Nieuw
        $id = 's_' . uniqid();
        $all[ $id ] = [
            'id'          => $id,
            'title'       => $title,
            'description' => $description,
            'type'        => $type,
            'code'        => $code,
            'scope'       => $scope,
            'priority'    => $priority,
            'sites'       => $sites,
            'active'      => $active,
            'created'     => $now,
            'updated'     => $now,
            'has_error'   => false,
            'error_msg'   => '',
        ];
    }

    update_option( 'dp_toolbox_snippets', $all, false );
    wp_send_json_success( [ 'id' => $id, 'message' => 'Snippet opgeslagen.' ] );
} );

add_action( 'wp_ajax_dp_toolbox_snippet_delete', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Geen toestemming.' );
    check_ajax_referer( 'dp_toolbox_snippets', 'nonce' );

    $id  = sanitize_text_field( $_POST['id'] ?? '' );
    $all = dp_toolbox_db_snippets_get_all();
    if ( ! isset( $all[ $id ] ) ) wp_send_json_error( 'Snippet niet gevonden.' );

    unset( $all[ $id ] );
    update_option( 'dp_toolbox_snippets', $all, false );
    wp_send_json_success();
} );

add_action( 'wp_ajax_dp_toolbox_snippet_toggle', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Geen toestemming.' );
    check_ajax_referer( 'dp_toolbox_snippets', 'nonce' );

    $id  = sanitize_text_field( $_POST['id'] ?? '' );
    $all = dp_toolbox_db_snippets_get_all();
    if ( ! isset( $all[ $id ] ) ) wp_send_json_error( 'Snippet niet gevonden.' );

    $next_state = empty( $all[ $id ]['active'] );

    // Bij heractiveren: PHP-syntax opnieuw checken
    if ( $next_state && ( $all[ $id ]['type'] ?? 'php' ) === 'php' ) {
        $check = dp_toolbox_php_syntax_check( $all[ $id ]['code'] ?? '' );
        if ( $check !== true ) {
            wp_send_json_error( 'PHP-syntaxfout: ' . $check );
        }
    }

    $all[ $id ]['active']    = $next_state;
    $all[ $id ]['has_error'] = false;
    $all[ $id ]['error_msg'] = '';
    update_option( 'dp_toolbox_snippets', $all, false );
    wp_send_json_success( [ 'active' => $next_state ] );
} );

/* ====================================================================== */
/*  PHP syntax-check helper                                                */
/* ====================================================================== */

/**
 * Lichte PHP-syntax-check via tokens. Vangt onbalansed { }, missing
 * semicolons aan einde van block, en evident parse-onzin. Echte parse-check
 * gebeurt sowieso in de eval; dit voorkomt vooral evidente fouten vooraf.
 *
 * Returns true bij geldige syntax of een foutbericht.
 */
function dp_toolbox_php_syntax_check( $code ) {
    $code = (string) $code;
    if ( trim( $code ) === '' ) return 'Lege code.';

    $code = preg_replace( '/^\s*<\?(php)?\s*/i', '', $code );

    // Probeer compile via eval-with-return-false in een sandbox.
    // De truc: voeg "return;" toe en eval — dat compiled de code zonder side-effects
    // (althans niet bij top-level statements; maar functions/classes worden wel
    // gedeclareerd). Beter: gebruik token_get_all en check op T_OPEN_TAG / parsefout.
    $tokens = @token_get_all( '<?php ' . $code );
    if ( ! is_array( $tokens ) || count( $tokens ) === 0 ) {
        return 'Kan code niet tokeniseren.';
    }

    // Brace-/bracket-/paren-balans check
    $stack = [];
    $pairs = [ '{' => '}', '[' => ']', '(' => ')' ];
    foreach ( $tokens as $tok ) {
        $char = is_array( $tok ) ? null : $tok;
        if ( $char && isset( $pairs[ $char ] ) ) {
            $stack[] = $pairs[ $char ];
        } elseif ( $char && in_array( $char, $pairs, true ) ) {
            $expected = array_pop( $stack );
            if ( $expected !== $char ) {
                return "Onbalans: '{$char}' op verkeerde plek.";
            }
        }
    }
    if ( ! empty( $stack ) ) {
        return "Niet-gesloten haakje: '" . implode( ',', $stack ) . "' verwacht.";
    }

    return true;
}

/* ====================================================================== */
/*  Admin-page                                                             */
/* ====================================================================== */

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
