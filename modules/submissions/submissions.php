<?php
/**
 * Module Name: Inzendingen
 * Description: Volledig overzicht van alle formulier-inzendingen (Bit Form en JetFormBuilder) — filteren per formulier, zoeken, detailweergave, CSV-export en verwijderen. Voegt een eigen 'Inzendingen'-menu toe.
 * Category: content
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Bronnen                                                            */
/*                                                                     */
/*  De module bediende oorspronkelijk alleen Bit Form. Sinds 1.1.0      */
/*  komen daar JetFormBuilder-inzendingen bij, in hetzelfde overzicht.  */
/*  Formulieren en inzendingen krijgen daarom een samengestelde sleutel */
/*  ("bf:12" / "jfb:197"), zodat de id's van beide plugins elkaar niet  */
/*  in de weg zitten.                                                   */
/* ------------------------------------------------------------------ */

function dp_toolbox_submissions_heeft_bitform() {
    global $wpdb;
    $t = $wpdb->prefix . 'bitforms_form_entries';
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
}

function dp_toolbox_submissions_heeft_jfb() {
    global $wpdb;
    $t = $wpdb->prefix . 'jet_fb_records';
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
}

function dp_toolbox_submissions_bronnen() {
    $b = [];
    if ( dp_toolbox_submissions_heeft_bitform() ) {
        $b['bf'] = 'Bit Form';
    }
    if ( dp_toolbox_submissions_heeft_jfb() ) {
        $b['jfb'] = 'JetFormBuilder';
    }
    return $b;
}

function dp_toolbox_submissions_active() {
    return (bool) dp_toolbox_submissions_bronnen();
}

/**
 * "bf:12" → [ 'bf', 12 ]. Zonder prefix nemen we Bit Form aan, zodat oude
 * bladwijzers en links naar ?entry=34 blijven werken.
 */
function dp_toolbox_submissions_split( $sleutel ) {
    $sleutel = (string) $sleutel;
    if ( strpos( $sleutel, ':' ) !== false ) {
        list( $bron, $id ) = explode( ':', $sleutel, 2 );
        if ( in_array( $bron, [ 'bf', 'jfb' ], true ) ) {
            return [ $bron, (int) $id ];
        }
    }
    return [ 'bf', (int) $sleutel ];
}

function dp_toolbox_submissions_sleutel( $bron, $id ) {
    return $bron . ':' . (int) $id;
}

/* ------------------------------------------------------------------ */
/*  Menu                                                               */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    if ( ! dp_toolbox_submissions_active() ) {
        return; // Geen formulierplugin met inzendingen → geen menu
    }
    add_menu_page(
        'Inzendingen',
        'Inzendingen',
        'manage_options',
        'dp-submissions',
        'dp_toolbox_submissions_render_page',
        'dashicons-email-alt',
        26 // Rond Reacties — natuurlijke plek voor inzendingen
    );
} );

/* ------------------------------------------------------------------ */
/*  Data-laag                                                          */
/* ------------------------------------------------------------------ */

/**
 * Velddefinities van een formulier (key => label/type/fieldName).
 * Leest form_content['fields'] correct (niet het top-level object).
 */
function dp_toolbox_submissions_get_fields( $form_key ) {
    global $wpdb;
    static $cache = [];
    if ( isset( $cache[ $form_key ] ) ) {
        return $cache[ $form_key ];
    }
    list( $bron, $form_id ) = dp_toolbox_submissions_split( $form_key );

    if ( 'jfb' === $bron ) {
        return $cache[ $form_key ] = dp_toolbox_submissions_jfb_fields( $form_id );
    }

    $content = $wpdb->get_var( $wpdb->prepare(
        "SELECT form_content FROM {$wpdb->prefix}bitforms_form WHERE id = %d",
        $form_id
    ) );

    $fields  = [];
    $decoded = $content ? json_decode( $content, true ) : null;
    // Bit Form nest de velden onder 'fields'; val terug op het top-level object.
    $raw = is_array( $decoded ) ? ( $decoded['fields'] ?? $decoded ) : [];

    if ( is_array( $raw ) ) {
        foreach ( $raw as $key => $f ) {
            if ( ! is_array( $f ) || ! isset( $f['typ'] ) ) {
                continue;
            }
            $fields[ $key ] = [
                'label'     => isset( $f['lbl'] ) && is_string( $f['lbl'] ) ? trim( wp_strip_all_tags( $f['lbl'] ) ) : '',
                'type'      => $f['typ'],
                'fieldName' => isset( $f['fieldName'] ) ? (string) $f['fieldName'] : '',
                'adminLbl'  => isset( $f['adminLbl'] ) && is_string( $f['adminLbl'] ) ? trim( $f['adminLbl'] ) : '',
            ];
        }
    }

    $cache[ $form_key ] = $fields;
    return $fields;
}

/**
 * Velddefinities van een JetFormBuilder-formulier.
 *
 * Die staan niet in een eigen tabel maar als Gutenberg-blokken in de
 * post_content van het formulier (post type jet-form-builder). We lopen de
 * blokstructuur af en pakken elk jet-forms/*-field met een 'name'.
 */
function dp_toolbox_submissions_jfb_fields( $form_id ) {
    $post = get_post( (int) $form_id );
    if ( ! $post || 'jet-form-builder' !== $post->post_type ) {
        return [];
    }

    $fields = [];
    $loop   = function ( $blokken ) use ( &$loop, &$fields ) {
        foreach ( $blokken as $b ) {
            $naam = $b['blockName'] ?? '';
            if ( 0 === strpos( $naam, 'jet-forms/' ) && ! empty( $b['attrs']['name'] ) ) {
                $type = str_replace( [ 'jet-forms/', '-field' ], '', $naam );
                // Een tekstveld met field_type 'email' is voor ons een e-mailveld.
                if ( 'text' === $type && ! empty( $b['attrs']['field_type'] ) ) {
                    $type = $b['attrs']['field_type'];
                }
                $sleutel = (string) $b['attrs']['name'];
                $fields[ $sleutel ] = [
                    'label'     => isset( $b['attrs']['label'] ) ? trim( wp_strip_all_tags( $b['attrs']['label'] ) ) : '',
                    'type'      => $type,
                    'fieldName' => $sleutel,
                    'adminLbl'  => '',
                ];
            }
            if ( ! empty( $b['innerBlocks'] ) ) {
                $loop( $b['innerBlocks'] );
            }
        }
    };
    $loop( parse_blocks( $post->post_content ) );

    return $fields;
}

/**
 * Velden die geen data dragen en dus overgeslagen worden in de weergave.
 */
function dp_toolbox_submissions_is_data_field( $info ) {
    $skip = [ 'button', 'divider', 'heading', 'step', 'recaptcha', 'turnstile', 'html', 'decoration', 'section', 'title' ];
    if ( in_array( $info['type'], $skip, true ) ) {
        return false;
    }
    // Sub-velden van een samengesteld veld (bv. name[first_name]) overslaan;
    // het bovenliggende veld toont de volledige waarde al.
    if ( strpos( $info['fieldName'], '[' ) !== false ) {
        return false;
    }
    return true;
}

/**
 * Een ruwe meta-waarde omzetten naar een leesbare string, op basis van veldtype.
 */
function dp_toolbox_submissions_format_value( $raw, $type ) {
    if ( $raw === null || $raw === '' ) {
        return '';
    }

    if ( $type === 'name' ) {
        $j = json_decode( $raw, true );
        if ( is_array( $j ) ) {
            $parts = array_filter( [
                $j['first_name']  ?? '',
                $j['middle_name'] ?? '',
                $j['last_name']   ?? '',
            ], 'strlen' );
            return trim( implode( ' ', $parts ) );
        }
    }

    if ( $type === 'gdpr' ) {
        $low = strtolower( (string) $raw );
        return ( $low === 'consented' || $low === 'checked' || $raw === '1' || $low === 'ja' ) ? 'Ja' : (string) $raw;
    }

    // Meervoudige waarden (checkbox/select) komen als JSON-array binnen.
    $j = json_decode( $raw, true );
    if ( is_array( $j ) ) {
        return implode( ', ', array_map( 'strval', $j ) );
    }

    return (string) $raw;
}

/**
 * Best-effort naam + e-mail uit een inzending halen (voor de lijst).
 */
function dp_toolbox_submissions_summary( $form_id, $meta ) {
    $fields = dp_toolbox_submissions_get_fields( $form_id );
    $name   = '';
    $email  = '';

    foreach ( $fields as $key => $info ) {
        $val = $meta[ $key ] ?? '';
        if ( $val === '' ) {
            continue;
        }
        if ( strpos( $info['fieldName'], '[' ) !== false ) {
            continue;
        }
        $lbl = strtolower( $info['label'] );

        if ( ! $name && ( $info['type'] === 'name' || strpos( $lbl, 'naam' ) !== false || strpos( $lbl, 'name' ) !== false ) ) {
            $name = dp_toolbox_submissions_format_value( $val, $info['type'] );
        }
        if ( ! $email && ( $info['type'] === 'email' || strpos( $lbl, 'mail' ) !== false ) ) {
            $email = dp_toolbox_submissions_format_value( $val, $info['type'] );
        }
    }

    return [ $name, $email ];
}

/**
 * Lijst van formulieren met aantal inzendingen (voor de filter-dropdown).
 */
function dp_toolbox_submissions_forms() {
    global $wpdb;
    $uit = [];

    if ( dp_toolbox_submissions_heeft_bitform() ) {
        $te = $wpdb->prefix . 'bitforms_form_entries';
        $tf = $wpdb->prefix . 'bitforms_form';
        foreach ( (array) $wpdb->get_results( "
            SELECT f.id, f.form_name, COUNT( e.id ) AS cnt
            FROM {$tf} f
            LEFT JOIN {$te} e ON e.form_id = f.id
            GROUP BY f.id
        " ) as $r ) {
            $uit[] = (object) [
                'key'       => dp_toolbox_submissions_sleutel( 'bf', $r->id ),
                'form_name' => $r->form_name,
                'cnt'       => (int) $r->cnt,
                'bron'      => 'Bit Form',
            ];
        }
    }

    if ( dp_toolbox_submissions_heeft_jfb() ) {
        $tr = $wpdb->prefix . 'jet_fb_records';
        $tellingen = [];
        foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS cnt FROM {$tr} GROUP BY form_id" ) as $r ) {
            $tellingen[ (int) $r->form_id ] = (int) $r->cnt;
        }
        foreach ( get_posts( [ 'post_type' => 'jet-form-builder', 'numberposts' => -1, 'post_status' => 'any' ] ) as $f ) {
            $uit[] = (object) [
                'key'       => dp_toolbox_submissions_sleutel( 'jfb', $f->ID ),
                'form_name' => $f->post_title,
                'cnt'       => $tellingen[ $f->ID ] ?? 0,
                'bron'      => 'JetFormBuilder',
            ];
        }
    }

    usort( $uit, function ( $a, $b ) { return strcasecmp( $a->form_name, $b->form_name ); } );
    return $uit;
}

/**
 * Gefilterde/gezochte inzendingen ophalen. Zonder per_page: alles (voor export).
 * Retour: [ rows, total ].
 */
/**
 * Inzendingen van álle bronnen, gefilterd en gezocht.
 *
 * De twee plugins bewaren hun data in losstaande tabellen die niet zinnig te
 * JOIN-en zijn. We halen daarom per bron op, voegen samen, sorteren op datum en
 * pagineren in PHP. Bij de aantallen waar dit om gaat (honderden inzendingen)
 * is dat ruim snel genoeg en een stuk begrijpelijker dan een UNION.
 *
 * Retour: [ rows, total ]. Elke row heeft: key, id, bron, form_key, form_name,
 * created_at, user_ip.
 */
function dp_toolbox_submissions_query( $args ) {
    global $wpdb;

    $filter_bron = '';
    $filter_id   = 0;
    if ( ! empty( $args['form_id'] ) ) {
        list( $filter_bron, $filter_id ) = dp_toolbox_submissions_split( $args['form_id'] );
    }
    $zoek = trim( (string) ( $args['search'] ?? '' ) );
    $alle = [];

    /* --- Bit Form --- */
    if ( dp_toolbox_submissions_heeft_bitform() && ( ! $filter_bron || 'bf' === $filter_bron ) ) {
        $te = $wpdb->prefix . 'bitforms_form_entries';
        $tf = $wpdb->prefix . 'bitforms_form';
        $tm = $wpdb->prefix . 'bitforms_form_entrymeta';

        $where  = '1=1';
        $params = [];
        if ( $filter_id ) {
            $where   .= ' AND e.form_id = %d';
            $params[] = $filter_id;
        }
        if ( '' !== $zoek ) {
            $like     = '%' . $wpdb->esc_like( $zoek ) . '%';
            $where   .= " AND ( e.id IN ( SELECT bitforms_form_entry_id FROM {$tm} WHERE meta_value LIKE %s ) OR f.form_name LIKE %s )";
            $params[] = $like;
            $params[] = $like;
        }
        $sql = "SELECT e.id, e.form_id, e.created_at, e.user_ip, f.form_name
                FROM {$te} e LEFT JOIN {$tf} f ON e.form_id = f.id
                WHERE {$where}";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        foreach ( (array) $rows as $r ) {
            $alle[] = (object) [
                'key'        => dp_toolbox_submissions_sleutel( 'bf', $r->id ),
                'id'         => (int) $r->id,
                'bron'       => 'bf',
                'form_key'   => dp_toolbox_submissions_sleutel( 'bf', $r->form_id ),
                'form_name'  => $r->form_name,
                'created_at' => $r->created_at,
                'user_ip'    => $r->user_ip,
            ];
        }
    }

    /* --- JetFormBuilder --- */
    if ( dp_toolbox_submissions_heeft_jfb() && ( ! $filter_bron || 'jfb' === $filter_bron ) ) {
        $tr = $wpdb->prefix . 'jet_fb_records';
        $tv = $wpdb->prefix . 'jet_fb_records_fields';

        $where  = '1=1';
        $params = [];
        if ( $filter_id ) {
            $where   .= ' AND r.form_id = %d';
            $params[] = $filter_id;
        }
        if ( '' !== $zoek ) {
            $like     = '%' . $wpdb->esc_like( $zoek ) . '%';
            $where   .= " AND r.id IN ( SELECT record_id FROM {$tv} WHERE field_value LIKE %s )";
            $params[] = $like;
        }
        $sql  = "SELECT r.id, r.form_id, r.created_at, r.ip_address FROM {$tr} r WHERE {$where}";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        $namen = [];
        foreach ( (array) $rows as $r ) {
            $fid = (int) $r->form_id;
            if ( ! isset( $namen[ $fid ] ) ) {
                $namen[ $fid ] = get_the_title( $fid ) ?: '';
            }
            $alle[] = (object) [
                'key'        => dp_toolbox_submissions_sleutel( 'jfb', $r->id ),
                'id'         => (int) $r->id,
                'bron'       => 'jfb',
                'form_key'   => dp_toolbox_submissions_sleutel( 'jfb', $fid ),
                'form_name'  => $namen[ $fid ],
                'created_at' => $r->created_at,
                'user_ip'    => $r->ip_address,
            ];
        }

        // Aanvullen met treffers op formuliernaam, die de SQL hierboven mist.
        if ( '' !== $zoek ) {
            $gevonden = wp_list_pluck( $alle, 'key' );
            $posts    = get_posts( [
                'post_type'   => 'jet-form-builder',
                'numberposts' => -1,
                'post_status' => 'any',
                's'           => $zoek,
            ] );
            foreach ( $posts as $p ) {
                if ( $filter_id && (int) $p->ID !== $filter_id ) {
                    continue;
                }
                $extra = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, form_id, created_at, ip_address FROM {$tr} WHERE form_id = %d",
                    $p->ID
                ) );
                foreach ( (array) $extra as $r ) {
                    $k = dp_toolbox_submissions_sleutel( 'jfb', $r->id );
                    if ( in_array( $k, $gevonden, true ) ) {
                        continue;
                    }
                    $alle[] = (object) [
                        'key'        => $k,
                        'id'         => (int) $r->id,
                        'bron'       => 'jfb',
                        'form_key'   => dp_toolbox_submissions_sleutel( 'jfb', $r->form_id ),
                        'form_name'  => $p->post_title,
                        'created_at' => $r->created_at,
                        'user_ip'    => $r->ip_address,
                    ];
                }
            }
        }
    }

    usort( $alle, function ( $a, $b ) {
        return strtotime( $b->created_at ) <=> strtotime( $a->created_at );
    } );

    $total = count( $alle );

    if ( ! empty( $args['per_page'] ) ) {
        $per    = max( 1, (int) $args['per_page'] );
        $paged  = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $alle   = array_slice( $alle, ( $paged - 1 ) * $per, $per );
    }

    return [ $alle, $total ];
}

/**
 * Meta-waarden voor een set entry-IDs. Retour: [ entry_id => [ key => value ] ].
 */
function dp_toolbox_submissions_meta_for( $entry_keys ) {
    global $wpdb;
    $bf = [];
    $jfb = [];
    foreach ( (array) $entry_keys as $k ) {
        list( $bron, $id ) = dp_toolbox_submissions_split( $k );
        if ( ! $id ) {
            continue;
        }
        if ( 'jfb' === $bron ) {
            $jfb[] = $id;
        } else {
            $bf[] = $id;
        }
    }

    $out = [];

    if ( $bf ) {
        $tm  = $wpdb->prefix . 'bitforms_form_entrymeta';
        $ids = implode( ',', array_map( 'intval', $bf ) );
        foreach ( (array) $wpdb->get_results( "
            SELECT bitforms_form_entry_id AS eid, meta_key, meta_value
            FROM {$tm} WHERE bitforms_form_entry_id IN ( {$ids} )
        " ) as $r ) {
            $out[ dp_toolbox_submissions_sleutel( 'bf', $r->eid ) ][ $r->meta_key ] = $r->meta_value;
        }
    }

    if ( $jfb ) {
        $tv  = $wpdb->prefix . 'jet_fb_records_fields';
        $ids = implode( ',', array_map( 'intval', $jfb ) );
        foreach ( (array) $wpdb->get_results( "
            SELECT record_id AS eid, field_name, field_value
            FROM {$tv} WHERE record_id IN ( {$ids} )
        " ) as $r ) {
            $out[ dp_toolbox_submissions_sleutel( 'jfb', $r->eid ) ][ $r->field_name ] = $r->field_value;
        }
    }

    return $out;
}

/**
 * Losse inzending ophalen (voor de detailweergave).
 */
function dp_toolbox_submissions_get_entry( $entry_key ) {
    global $wpdb;
    list( $bron, $id ) = dp_toolbox_submissions_split( $entry_key );
    if ( ! $id ) {
        return null;
    }

    if ( 'jfb' === $bron ) {
        $tr  = $wpdb->prefix . 'jet_fb_records';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, form_id, created_at, ip_address, user_agent, status FROM {$tr} WHERE id = %d",
            $id
        ) );
        if ( ! $row ) {
            return null;
        }
        return (object) [
            'key'         => dp_toolbox_submissions_sleutel( 'jfb', $row->id ),
            'id'          => (int) $row->id,
            'bron'        => 'jfb',
            'form_key'    => dp_toolbox_submissions_sleutel( 'jfb', $row->form_id ),
            'form_name'   => get_the_title( (int) $row->form_id ) ?: '',
            'created_at'  => $row->created_at,
            'user_ip'     => $row->ip_address,
            'user_device' => $row->user_agent,
        ];
    }

    $te  = $wpdb->prefix . 'bitforms_form_entries';
    $tf  = $wpdb->prefix . 'bitforms_form';
    $row = $wpdb->get_row( $wpdb->prepare( "
        SELECT e.id, e.form_id, e.created_at, e.user_ip, e.user_device, f.form_name
        FROM {$te} e LEFT JOIN {$tf} f ON e.form_id = f.id
        WHERE e.id = %d
    ", $id ) );
    if ( ! $row ) {
        return null;
    }
    return (object) [
        'key'         => dp_toolbox_submissions_sleutel( 'bf', $row->id ),
        'id'          => (int) $row->id,
        'bron'        => 'bf',
        'form_key'    => dp_toolbox_submissions_sleutel( 'bf', $row->form_id ),
        'form_name'   => $row->form_name,
        'created_at'  => $row->created_at,
        'user_ip'     => $row->user_ip,
        'user_device' => $row->user_device,
    ];
}

/**
 * IP leesbaar maken (Bit Form slaat het als ip2long-integer op).
 */
function dp_toolbox_submissions_format_ip( $ip ) {
    if ( $ip === null || $ip === '' ) {
        return '';
    }
    if ( is_numeric( $ip ) ) {
        $long = long2ip( (int) $ip );
        return $long ?: (string) $ip;
    }
    return (string) $ip;
}

/* ------------------------------------------------------------------ */
/*  Verwijderen (admin_post — draait vóór output, dus redirect kan)    */
/* ------------------------------------------------------------------ */

add_action( 'admin_post_dp_toolbox_submissions_delete', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toestemming.' );
    }
    $entry_key = isset( $_GET['entry'] ) ? sanitize_text_field( wp_unslash( $_GET['entry'] ) ) : '';
    check_admin_referer( 'dp_submissions_delete_' . $entry_key );

    list( $bron, $entry_id ) = dp_toolbox_submissions_split( $entry_key );

    if ( $entry_id ) {
        global $wpdb;
        if ( 'jfb' === $bron ) {
            $wpdb->delete( $wpdb->prefix . 'jet_fb_records',         [ 'id' => $entry_id ] );
            $wpdb->delete( $wpdb->prefix . 'jet_fb_records_fields',  [ 'record_id' => $entry_id ] );
            $wpdb->delete( $wpdb->prefix . 'jet_fb_records_actions', [ 'record_id' => $entry_id ] );
        } else {
            $wpdb->delete( $wpdb->prefix . 'bitforms_form_entries',   [ 'id' => $entry_id ] );
            $wpdb->delete( $wpdb->prefix . 'bitforms_form_entrymeta', [ 'bitforms_form_entry_id' => $entry_id ] );
            // Best-effort opruimen van gekoppelde logs (kolomnamen kunnen per versie verschillen).
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}bitforms_form_entry_log WHERE form_entry_id = %d",
                $entry_id
            ) );
        }
    }

    $back = add_query_arg(
        [ 'page' => 'dp-submissions', 'dp_msg' => 'deleted' ],
        admin_url( 'admin.php' )
    );
    wp_safe_redirect( $back );
    exit;
} );

/* ------------------------------------------------------------------ */
/*  CSV-export (admin_post)                                            */
/* ------------------------------------------------------------------ */

/** Cel beschermen tegen CSV-injectie in spreadsheet-programma's. */
function dp_toolbox_submissions_csv_cell( $value ) {
    $value = (string) $value;
    if ( $value !== '' && in_array( $value[0], [ '=', '+', '-', '@' ], true ) ) {
        $value = "'" . $value;
    }
    return $value;
}

add_action( 'admin_post_dp_toolbox_submissions_export', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toestemming.' );
    }
    check_admin_referer( 'dp_submissions_export' );

    $form_id = isset( $_GET['form_id'] ) ? sanitize_text_field( wp_unslash( $_GET['form_id'] ) ) : '';
    $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

    list( $rows ) = dp_toolbox_submissions_query( [
        'form_id' => $form_id,
        'search'  => $search,
    ] );

    $meta = dp_toolbox_submissions_meta_for( wp_list_pluck( $rows, 'key' ) );

    $filename = 'inzendingen-' . gmdate( 'Y-m-d' ) . '.csv';
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $out = fopen( 'php://output', 'w' );
    // UTF-8 BOM zodat Excel accenten correct toont.
    fwrite( $out, "\xEF\xBB\xBF" );

    if ( $form_id ) {
        // Eén formulier → kolom per veld.
        $fields = dp_toolbox_submissions_get_fields( $form_id );
        $cols   = [];
        foreach ( $fields as $key => $info ) {
            if ( dp_toolbox_submissions_is_data_field( $info ) ) {
                $cols[ $key ] = $info['label'] ?: $key;
            }
        }
        $header = array_merge( [ 'Datum', 'Formulier' ], array_values( $cols ), [ 'IP' ] );
        fputcsv( $out, array_map( 'dp_toolbox_submissions_csv_cell', $header ) );

        foreach ( $rows as $r ) {
            $m   = $meta[ $r->key ] ?? [];
            $row = [
                date_i18n( 'Y-m-d H:i', strtotime( $r->created_at ) ),
                $r->form_name ?: $r->form_key,
            ];
            foreach ( $cols as $key => $label ) {
                $row[] = dp_toolbox_submissions_format_value( $m[ $key ] ?? '', $fields[ $key ]['type'] ?? '' );
            }
            $row[] = dp_toolbox_submissions_format_ip( $r->user_ip );
            fputcsv( $out, array_map( 'dp_toolbox_submissions_csv_cell', $row ) );
        }
    } else {
        // Alle formulieren → generieke kolommen + gecombineerde inhoud.
        fputcsv( $out, array_map( 'dp_toolbox_submissions_csv_cell', [ 'Datum', 'Formulier', 'Naam', 'E-mail', 'IP', 'Inhoud' ] ) );

        foreach ( $rows as $r ) {
            $m               = $meta[ $r->key ] ?? [];
            list( $name, $email ) = dp_toolbox_submissions_summary( $r->form_key, $m );
            $fields          = dp_toolbox_submissions_get_fields( $r->form_key );
            $bits            = [];
            foreach ( $fields as $key => $info ) {
                if ( ! dp_toolbox_submissions_is_data_field( $info ) ) {
                    continue;
                }
                $val = dp_toolbox_submissions_format_value( $m[ $key ] ?? '', $info['type'] );
                if ( $val !== '' ) {
                    $bits[] = ( $info['label'] ?: $key ) . ': ' . $val;
                }
            }
            fputcsv( $out, array_map( 'dp_toolbox_submissions_csv_cell', [
                date_i18n( 'Y-m-d H:i', strtotime( $r->created_at ) ),
                $r->form_name ?: $r->form_key,
                $name,
                $email,
                dp_toolbox_submissions_format_ip( $r->user_ip ),
                implode( ' | ', $bits ),
            ] ) );
        }
    }

    fclose( $out );
    exit;
} );

/* ------------------------------------------------------------------ */
/*  Pagina-render                                                      */
/* ------------------------------------------------------------------ */

function dp_toolbox_submissions_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toestemming.' );
    }

    // Detailweergave?
    $entry_id = isset( $_GET['entry'] ) ? sanitize_text_field( wp_unslash( $_GET['entry'] ) ) : '';
    if ( $entry_id ) {
        dp_toolbox_submissions_render_detail( $entry_id );
        return;
    }
    dp_toolbox_submissions_render_list();
}

/**
 * Lijstweergave met filter, zoeken, tabel, paginering en export.
 */
function dp_toolbox_submissions_render_list() {
    $form_id  = isset( $_GET['form_id'] ) ? sanitize_text_field( wp_unslash( $_GET['form_id'] ) ) : '';
    $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $per_page = 20;

    list( $rows, $total ) = dp_toolbox_submissions_query( [
        'form_id'  => $form_id,
        'search'   => $search,
        'paged'    => $paged,
        'per_page' => $per_page,
    ] );

    $meta       = dp_toolbox_submissions_meta_for( wp_list_pluck( $rows, 'id' ) );
    $forms      = dp_toolbox_submissions_forms();
    $total_pages = (int) ceil( $total / $per_page );

    $export_url = wp_nonce_url(
        add_query_arg(
            array_filter( [
                'action'  => 'dp_toolbox_submissions_export',
                'form_id' => $form_id ?: null,
                's'       => $search ?: null,
            ] ),
            admin_url( 'admin-post.php' )
        ),
        'dp_submissions_export'
    );
    ?>
    <div class="wrap dp-subs">
        <h1 class="dp-subs-title">
            <span class="dashicons dashicons-email-alt"></span>
            Inzendingen
            <span class="dp-subs-count"><?php echo (int) $total; ?></span>
        </h1>

        <?php if ( isset( $_GET['dp_msg'] ) && $_GET['dp_msg'] === 'deleted' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Inzending verwijderd.</p></div>
        <?php endif; ?>

        <form method="get" class="dp-subs-toolbar">
            <input type="hidden" name="page" value="dp-submissions">

            <select name="form_id" onchange="this.form.submit()">
                <option value="">Alle formulieren</option>
                <?php foreach ( $forms as $f ) : ?>
                    <option value="<?php echo esc_attr( $f->key ); ?>" <?php selected( $form_id, $f->key ); ?>>
                        <?php
                        $label = $f->form_name ?: $f->key;
                        // Bronnaam erbij zodra er meer dan één formulierplugin draait,
                        // anders is "Contact" uit twee plugins niet uit elkaar te houden.
                        if ( count( dp_toolbox_submissions_bronnen() ) > 1 ) {
                            $label .= ' — ' . $f->bron;
                        }
                        echo esc_html( $label . ' (' . (int) $f->cnt . ')' );
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <span class="dp-subs-search">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Zoek op naam, e-mail of inhoud&hellip;">
                <button type="submit" class="button">Zoeken</button>
            </span>

            <?php if ( $total > 0 ) : ?>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button dp-subs-export">
                    <span class="dashicons dashicons-download"></span> Exporteer CSV
                </a>
            <?php endif; ?>
        </form>

        <?php if ( empty( $rows ) ) : ?>
            <div class="dp-subs-empty">
                <?php echo $search || $form_id ? 'Geen inzendingen gevonden voor deze filter.' : 'Nog geen formulier-inzendingen ontvangen.'; ?>
            </div>
        <?php else : ?>
            <table class="widefat striped dp-subs-table">
                <thead>
                    <tr>
                        <th>Inzender</th>
                        <th>Formulier</th>
                        <th>Datum</th>
                        <th class="dp-subs-actions-col">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) :
                        $m              = $meta[ $r->key ] ?? [];
                        list( $name, $email ) = dp_toolbox_submissions_summary( $r->form_key, $m );
                        $display        = $name ?: $email ?: 'Anoniem';
                        $view_url       = add_query_arg( [ 'page' => 'dp-submissions', 'entry' => $r->key ], admin_url( 'admin.php' ) );
                        $delete_url     = wp_nonce_url(
                            add_query_arg(
                                [ 'action' => 'dp_toolbox_submissions_delete', 'entry' => $r->key ],
                                admin_url( 'admin-post.php' )
                            ),
                            'dp_submissions_delete_' . $r->key
                        );
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $view_url ); ?>" class="dp-subs-name">
                                    <span class="dp-subs-avatar"><?php echo esc_html( mb_strtoupper( mb_substr( $display, 0, 1 ) ) ); ?></span>
                                    <span>
                                        <strong><?php echo esc_html( $display ); ?></strong>
                                        <?php if ( $email && $email !== $display ) : ?>
                                            <span class="dp-subs-sub"><?php echo esc_html( $email ); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </td>
                            <td><?php echo esc_html( $r->form_name ?: $r->form_key ); ?></td>
                            <td>
                                <?php echo esc_html( date_i18n( 'j M Y, H:i', strtotime( $r->created_at ) ) ); ?>
                                <span class="dp-subs-ago"><?php echo esc_html( human_time_diff( strtotime( $r->created_at ), current_time( 'timestamp' ) ) ); ?> geleden</span>
                            </td>
                            <td class="dp-subs-actions-col">
                                <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">Bekijk</a>
                                <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small dp-subs-del"
                                   onclick="return confirm('Deze inzending definitief verwijderen?');">Verwijder</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                // Sentinel-getal dat we ná URL-opbouw vervangen door %#%, zodat
                // add_query_arg de placeholder niet URL-encodeert.
                $big  = 999999999;
                $base = add_query_arg( array_filter( [
                    'page'    => 'dp-submissions',
                    'form_id' => $form_id ?: null,
                    's'       => $search ?: null,
                    'paged'   => $big,
                ] ), admin_url( 'admin.php' ) );
                $base = str_replace( (string) $big, '%#%', $base );
                $links = paginate_links( [
                    'base'      => $base,
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
            ?>
                <div class="dp-subs-pagination tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $links ); ?></div></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Detailweergave van één inzending: alle velden netjes uitgelijst.
 */
function dp_toolbox_submissions_render_detail( $entry_id ) {
    $entry = dp_toolbox_submissions_get_entry( $entry_id );

    if ( ! $entry ) {
        echo '<div class="wrap dp-subs"><h1>Inzending niet gevonden</h1><p><a href="' .
            esc_url( admin_url( 'admin.php?page=dp-submissions' ) ) . '">&laquo; Terug naar overzicht</a></p></div>';
        return;
    }

    $fields = dp_toolbox_submissions_get_fields( $entry->form_key );
    $meta   = dp_toolbox_submissions_meta_for( [ $entry_id ] )[ $entry_id ] ?? [];
    list( $name, $email ) = dp_toolbox_submissions_summary( $entry->form_key, $meta );
    $display = $name ?: $email ?: 'Anoniem';

    $back_url   = add_query_arg( [ 'page' => 'dp-submissions' ], admin_url( 'admin.php' ) );
    $delete_url = wp_nonce_url(
        add_query_arg( [ 'action' => 'dp_toolbox_submissions_delete', 'entry' => $entry_id ], admin_url( 'admin-post.php' ) ),
        'dp_submissions_delete_' . $entry_id
    );
    // Link terug naar de bewerkpagina van het formulier in de plugin zelf.
    list( $bron_slug, $bron_form_id ) = dp_toolbox_submissions_split( $entry->form_key );
    if ( 'jfb' === $bron_slug ) {
        $bron_label = 'JetFormBuilder';
        $bron_url   = get_edit_post_link( $bron_form_id, '' );
    } else {
        $bron_label = 'Bit Form';
        $bron_url   = admin_url( 'admin.php?page=bitform#/' . $bron_form_id );
    }
    ?>
    <div class="wrap dp-subs dp-subs-detail-wrap">
        <p class="dp-subs-back"><a href="<?php echo esc_url( $back_url ); ?>">&laquo; Terug naar overzicht</a></p>

        <div class="dp-subs-detail-head">
            <div>
                <h1><?php echo esc_html( $display ); ?></h1>
                <div class="dp-subs-detail-meta">
                    <span><span class="dashicons dashicons-feedback"></span> <?php echo esc_html( $entry->form_name ?: $entry->form_key ); ?></span>
                    <span><span class="dashicons dashicons-admin-plugins"></span> <?php echo esc_html( $bron_label ); ?></span>
                    <span><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html( date_i18n( 'j M Y, H:i', strtotime( $entry->created_at ) ) ); ?></span>
                    <?php if ( $entry->user_ip ) : ?>
                        <span><span class="dashicons dashicons-admin-site"></span> <?php echo esc_html( dp_toolbox_submissions_format_ip( $entry->user_ip ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $entry->user_device ) : ?>
                        <span><span class="dashicons dashicons-laptop"></span> <?php echo esc_html( $entry->user_device ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dp-subs-detail-actions">
                <?php if ( $bron_url ) : ?>
                    <a href="<?php echo esc_url( $bron_url ); ?>" class="button">Open in <?php echo esc_html( $bron_label ); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url( $delete_url ); ?>" class="button dp-subs-del"
                   onclick="return confirm('Deze inzending definitief verwijderen?');">Verwijder</a>
            </div>
        </div>

        <table class="dp-subs-detail-table">
            <tbody>
            <?php
            $shown = 0;
            foreach ( $fields as $key => $info ) {
                if ( ! dp_toolbox_submissions_is_data_field( $info ) ) {
                    continue;
                }
                $val = dp_toolbox_submissions_format_value( $meta[ $key ] ?? '', $info['type'] );
                $label = $info['label'] ?: ( $info['adminLbl'] ?: $key );
                $shown++;
                ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td><?php echo $val === '' ? '<span class="dp-subs-muted">&mdash;</span>' : nl2br( esc_html( $val ) ); ?></td>
                </tr>
                <?php
            }
            if ( ! $shown ) {
                echo '<tr><td class="dp-subs-muted">Geen veldgegevens gevonden voor deze inzending.</td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Stijl                                                              */
/* ------------------------------------------------------------------ */

add_action( 'admin_head', function () {
    if ( ( $_GET['page'] ?? '' ) !== 'dp-submissions' ) {
        return;
    }
    ?>
    <style>
        .dp-subs-title { display: flex; align-items: center; gap: 8px; }
        .dp-subs-title .dashicons { color: #281E5D; font-size: 26px; width: 26px; height: 26px; }
        .dp-subs-count { background: #281E5D; color: #fff; font-size: 13px; font-weight: 600; border-radius: 999px; padding: 2px 11px; }

        .dp-subs-toolbar { display: flex; align-items: center; gap: 12px; margin: 16px 0 18px; flex-wrap: wrap; }
        .dp-subs-toolbar select { max-width: 320px; }
        .dp-subs-search { display: flex; gap: 6px; }
        .dp-subs-search input[type="search"] { min-width: 260px; }
        .dp-subs-export { margin-left: auto; display: inline-flex; align-items: center; gap: 4px; }
        .dp-subs-export .dashicons { font-size: 16px; width: 16px; height: 16px; margin-top: 4px; }

        .dp-subs-empty { background: #fff; border: 1px solid #e0dcec; border-radius: 10px; padding: 40px; text-align: center; color: #6b6b7b; font-size: 15px; }

        .dp-subs-table { border-radius: 10px; overflow: hidden; }
        .dp-subs-table th { font-weight: 600; }
        .dp-subs-name { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #1d2327; }
        .dp-subs-avatar { flex: 0 0 auto; width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #281E5D, #7c3aed); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .dp-subs-name strong { display: block; }
        .dp-subs-sub { color: #787885; font-size: 12px; }
        .dp-subs-ago { display: block; color: #a0a0ad; font-size: 12px; }
        .dp-subs-actions-col { white-space: nowrap; text-align: right; }
        .dp-subs-del { color: #b32d2e !important; border-color: #d9a6a6 !important; }
        .dp-subs-del:hover { background: #b32d2e !important; color: #fff !important; border-color: #b32d2e !important; }
        .dp-subs-pagination { margin-top: 12px; }

        /* Detail */
        .dp-subs-back a { text-decoration: none; }
        .dp-subs-detail-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; background: #fff; border: 1px solid #e0dcec; border-radius: 10px 10px 0 0; padding: 22px 26px; flex-wrap: wrap; }
        .dp-subs-detail-head h1 { margin: 0 0 8px; padding: 0; }
        .dp-subs-detail-meta { display: flex; flex-wrap: wrap; gap: 16px; color: #6b6b7b; font-size: 13px; }
        .dp-subs-detail-meta .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; color: #281E5D; }
        .dp-subs-detail-actions { display: flex; gap: 8px; }
        .dp-subs-detail-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e0dcec; border-top: 0; border-radius: 0 0 10px 10px; overflow: hidden; }
        .dp-subs-detail-table th { text-align: left; width: 230px; padding: 14px 26px; background: #faf9fd; color: #281E5D; font-weight: 600; vertical-align: top; border-top: 1px solid #eee; }
        .dp-subs-detail-table td { padding: 14px 26px; vertical-align: top; border-top: 1px solid #eee; }
        .dp-subs-muted { color: #a0a0ad; }
        @media (max-width: 640px) {
            .dp-subs-detail-table th { width: 40%; }
            .dp-subs-export { margin-left: 0; }
        }
    </style>
    <?php
} );
