<?php
/**
 * DP Toolbox — Import / Export voor instellingen
 *
 * Definieert welke opties exporteerbaar zijn, genereert JSON-exports,
 * en importeert JSON-bestanden terug in de database.
 *
 * Site-specifieke data (activity log, user manager rules, redirects, wachtwoorden,
 * API-keys) wordt bewust NIET geexporteerd.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/*  Registry: exporteerbare opties per categorie                       */
/* ------------------------------------------------------------------ */

function dp_toolbox_ie_get_categories() {
    return [
        'modules' => [
            'label' => 'Modules',
            'desc'  => 'Welke modules zijn geactiveerd.',
            'options' => [
                'dp_toolbox_enabled_modules',
            ],
        ],
        'access' => [
            'label' => 'Toegang',
            'desc'  => 'Toegestane gebruikersrollen voor DP Toolbox. Geblokkeerde admins zijn site-specifiek en worden NIET meegenomen.',
            'options' => [
                'dp_toolbox_allowed_roles',
            ],
        ],
        'dashboard' => [
            'label' => 'Dashboard Widgets',
            'desc'  => 'Welke widget-secties aan staan, tutorials en visitekaartje. API-key wordt NIET meegenomen.',
            'options' => [
                'dp_toolbox_dashboard_welkom',
                'dp_toolbox_dashboard_analytics',
                'dp_toolbox_dashboard_forms',
                'dp_toolbox_dashboard_converter',
                'dp_toolbox_dashboard_punch_card',
                'dp_toolbox_dashboard_tutorials',
                'dp_toolbox_dashboard_tutorial_urls',
                'dp_toolbox_dashboard_businesscard',
                'dp_toolbox_dashboard_hide_defaults',
            ],
        ],
        'security' => [
            'label' => 'Beveiliging',
            'desc'  => 'Security headers en revisie-limiet. Custom login slug wordt NIET meegenomen (per site uniek).',
            'options' => [
                'dp_toolbox_security_headers',
                'dp_toolbox_revision_limit',
            ],
        ],
        'appearance' => [
            'label' => 'Uiterlijk',
            'desc'  => 'Admin branding-kleur en site navigator toggles.',
            'options' => [
                'dp_toolbox_branding_color',
                'dp_toolbox_site_nav_show_admin_bar_in_editor',
                'dp_toolbox_site_nav_show_bricks_settings',
                'dp_toolbox_site_nav_show_plugin_settings',
            ],
        ],
        'email' => [
            'label' => 'E-mail (SMTP)',
            'desc'  => 'SMTP host/port/encryption/afzender. Wachtwoord wordt NIET meegenomen — vul handmatig in op de nieuwe site.',
            'options' => [
                'dp_toolbox_smtp_host',
                'dp_toolbox_smtp_port',
                'dp_toolbox_smtp_encryption',
                'dp_toolbox_smtp_auth',
                'dp_toolbox_smtp_username',
                'dp_toolbox_smtp_from_email',
                'dp_toolbox_smtp_from_name',
            ],
        ],
    ];
}

/**
 * Per definitie NOOIT exporteren — secrets + site-specifiek.
 */
function dp_toolbox_ie_get_excluded_options() {
    return [
        // Secrets
        'dp_toolbox_smtp_password',
        'dp_toolbox_dashboard_api_key',
        // Site-specifiek
        'dp_toolbox_login_slug',
        'dp_toolbox_maintenance_enabled',
        'dp_toolbox_blocked_users',
        // Runtime state
        'dp_toolbox_dashboard_migrated',
        'dp_toolbox_rm_all_menus',
        'dp_toolbox_rm_all_submenus',
        'dp_toolbox_punch_card_data',
        'dp_toolbox_analytics_data',
    ];
}

/* ------------------------------------------------------------------ */
/*  Export                                                             */
/* ------------------------------------------------------------------ */

function dp_toolbox_ie_build_export( $selected_categories = [] ) {
    $categories = dp_toolbox_ie_get_categories();
    $excluded   = dp_toolbox_ie_get_excluded_options();

    $export = [];
    foreach ( $categories as $key => $cat ) {
        if ( ! empty( $selected_categories ) && ! in_array( $key, $selected_categories, true ) ) {
            continue;
        }
        $bucket = [];
        foreach ( $cat['options'] as $opt ) {
            if ( in_array( $opt, $excluded, true ) ) continue;
            $val = get_option( $opt, null );
            if ( null === $val ) continue;
            $bucket[ $opt ] = $val;
        }
        if ( ! empty( $bucket ) ) {
            $export[ $key ] = $bucket;
        }
    }

    return [
        'format'         => 'dp-toolbox-settings',
        'plugin_version' => defined( 'DP_TOOLBOX_VERSION' ) ? DP_TOOLBOX_VERSION : '',
        'exported_at'    => gmdate( 'c' ),
        'source_site'    => home_url(),
        'categories'     => array_keys( $export ),
        'data'           => $export,
    ];
}

/**
 * Handler voor ?action=dp_toolbox_ie_export (admin_post)
 */
add_action( 'admin_post_dp_toolbox_ie_export', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toegang.' );
    }
    check_admin_referer( 'dp_toolbox_ie_export' );

    $selected = array_map( 'sanitize_key', (array) ( $_POST['categories'] ?? [] ) );
    if ( empty( $selected ) ) {
        wp_safe_redirect( add_query_arg( [
            'page' => 'dp-toolbox',
            'tab'  => 'admin',
            'ie_error' => 'no_categories',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $export = dp_toolbox_ie_build_export( $selected );

    $site_slug = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );
    $filename  = 'dp-toolbox-' . $site_slug . '-' . gmdate( 'Ymd-His' ) . '.json';

    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
} );

/* ------------------------------------------------------------------ */
/*  Import                                                             */
/* ------------------------------------------------------------------ */

function dp_toolbox_ie_apply_import( $payload, $selected_categories = [] ) {
    if ( ! is_array( $payload ) || ( $payload['format'] ?? '' ) !== 'dp-toolbox-settings' ) {
        return [ 'success' => false, 'message' => 'Ongeldig bestandsformaat.' ];
    }
    if ( empty( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
        return [ 'success' => false, 'message' => 'Geen data in het export-bestand.' ];
    }

    $categories = dp_toolbox_ie_get_categories();
    $excluded   = dp_toolbox_ie_get_excluded_options();
    $applied    = 0;
    $skipped    = 0;

    foreach ( $payload['data'] as $cat_key => $options ) {
        if ( ! isset( $categories[ $cat_key ] ) ) {
            $skipped += is_array( $options ) ? count( $options ) : 0;
            continue;
        }
        if ( ! empty( $selected_categories ) && ! in_array( $cat_key, $selected_categories, true ) ) {
            $skipped += is_array( $options ) ? count( $options ) : 0;
            continue;
        }

        $allowed = $categories[ $cat_key ]['options'];
        foreach ( (array) $options as $opt_name => $value ) {
            if ( in_array( $opt_name, $excluded, true ) )    continue;
            if ( ! in_array( $opt_name, $allowed, true ) )   continue;
            update_option( $opt_name, $value );
            $applied++;
        }
    }

    return [
        'success' => true,
        'message' => sprintf( '%d instellingen toegepast, %d overgeslagen.', $applied, $skipped ),
    ];
}

/**
 * Handler voor ?action=dp_toolbox_ie_import (admin_post)
 */
add_action( 'admin_post_dp_toolbox_ie_import', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toegang.' );
    }
    check_admin_referer( 'dp_toolbox_ie_import' );

    if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
        wp_safe_redirect( add_query_arg( [
            'page' => 'dp-toolbox', 'tab' => 'admin', 'ie_error' => 'no_file',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $json = file_get_contents( $_FILES['import_file']['tmp_name'] );
    if ( ! $json ) {
        wp_safe_redirect( add_query_arg( [
            'page' => 'dp-toolbox', 'tab' => 'admin', 'ie_error' => 'read_failed',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $payload = json_decode( $json, true );
    if ( ! is_array( $payload ) ) {
        wp_safe_redirect( add_query_arg( [
            'page' => 'dp-toolbox', 'tab' => 'admin', 'ie_error' => 'invalid_json',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $selected = array_map( 'sanitize_key', (array) ( $_POST['categories'] ?? [] ) );
    $result   = dp_toolbox_ie_apply_import( $payload, $selected );

    $args = [ 'page' => 'dp-toolbox', 'tab' => 'admin' ];
    if ( $result['success'] ) {
        $args['ie_imported'] = rawurlencode( $result['message'] );
    } else {
        $args['ie_error'] = rawurlencode( $result['message'] );
    }
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
} );

/* ------------------------------------------------------------------ */
/*  Preview van geselecteerde categorieen (toont inhoud zonder te importeren)
 * ------------------------------------------------------------------ */

function dp_toolbox_ie_preview( $payload ) {
    if ( ! is_array( $payload ) || ( $payload['format'] ?? '' ) !== 'dp-toolbox-settings' ) {
        return null;
    }
    $categories = dp_toolbox_ie_get_categories();
    $out        = [];
    foreach ( (array) ( $payload['data'] ?? [] ) as $cat_key => $options ) {
        if ( ! isset( $categories[ $cat_key ] ) ) continue;
        $out[ $cat_key ] = [
            'label'  => $categories[ $cat_key ]['label'],
            'count'  => is_array( $options ) ? count( $options ) : 0,
        ];
    }
    return [
        'plugin_version' => $payload['plugin_version'] ?? '',
        'exported_at'    => $payload['exported_at']    ?? '',
        'source_site'    => $payload['source_site']    ?? '',
        'categories'     => $out,
    ];
}
