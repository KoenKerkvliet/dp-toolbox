<?php
/**
 * Module Name: Media Replacement
 * Description: Vervang mediabestanden met behoud van ID, datum en bestandsnaam. Een vervangen bestand krijgt overal een nieuwe versie-URL (?v=), zodat een CDN of browser direct de nieuwe versie toont; ook de productfoto-URL's die FluentCart opslaat gaan mee.
 * Category: media
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Enqueue assets on media pages                                      */
/* ------------------------------------------------------------------ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    // Load on media library (grid modal) and attachment edit screen
    if ( ! in_array( $hook, [ 'post.php', 'upload.php' ], true ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    // upload.php = media library grid, post.php with attachment = edit screen
    if ( $hook === 'upload.php' || ( $hook === 'post.php' && $screen->post_type === 'attachment' ) ) {
        wp_enqueue_media();

        $module_url = DP_TOOLBOX_URL . 'modules/media-replacement/assets/';

        wp_enqueue_style(
            'dp-toolbox-media-replace',
            $module_url . 'media-replace.css',
            [],
            DP_TOOLBOX_VERSION
        );

        wp_enqueue_script(
            'dp-toolbox-media-replace',
            $module_url . 'media-replace.js',
            [ 'jquery', 'media-upload' ],
            DP_TOOLBOX_VERSION,
            true
        );

        wp_localize_script( 'dp-toolbox-media-replace', 'dpMR', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dp_toolbox_media_replace' ),
        ] );
    }
} );

/* ------------------------------------------------------------------ */
/*  Add "Replace Media" button to attachment edit fields                */
/* ------------------------------------------------------------------ */

add_filter( 'attachment_fields_to_edit', 'dp_toolbox_mr_add_replace_button', 10, 2 );

function dp_toolbox_mr_add_replace_button( $fields, $post ) {
    if ( ! current_user_can( 'upload_files' ) ) {
        return $fields;
    }

    $mime_type = get_post_mime_type( $post->ID );

    ob_start();
    ?>
    <div class="dp-mr-wrap" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>">
        <button type="button" class="button button-small dp-mr-btn">
            <span class="dashicons dashicons-update"></span>
            Media vervangen
        </button>
        <span class="dp-mr-status"></span>
        <p class="dp-mr-hint">Zelfde bestandstype (<?php echo esc_html( $mime_type ); ?>). ID en links blijven behouden.</p>
    </div>
    <?php
    $html = ob_get_clean();

    $fields['dp_media_replace'] = [
        'label' => 'Vervangen',
        'input' => 'html',
        'html'  => $html,
    ];

    return $fields;
}

/* ------------------------------------------------------------------ */
/*  Modify "Edit" link in media list table                             */
/* ------------------------------------------------------------------ */

add_filter( 'media_row_actions', 'dp_toolbox_mr_modify_row_actions', 10, 2 );

function dp_toolbox_mr_modify_row_actions( $actions, $post ) {
    if ( isset( $actions['edit'] ) && current_user_can( 'upload_files' ) ) {
        $actions['edit'] = str_replace( '>Bewerken<', '>Bewerken / Vervangen<', $actions['edit'] );
        // Fallback for English
        $actions['edit'] = str_replace( '>Edit<', '>Edit / Replace<', $actions['edit'] );
    }
    return $actions;
}

/* ------------------------------------------------------------------ */
/*  AJAX handler for media replacement                                 */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_replace_media', function () {
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }

    check_ajax_referer( 'dp_toolbox_media_replace', 'nonce' );

    $old_id = absint( $_POST['old_attachment_id'] ?? 0 );
    $new_id = absint( $_POST['new_attachment_id'] ?? 0 );

    if ( ! $old_id || ! $new_id ) {
        wp_send_json_error( 'Ongeldige attachment IDs.' );
    }

    $result = dp_toolbox_mr_do_replace( $old_id, $new_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( [
        'message' => 'Media succesvol vervangen. De website toont de nieuwe versie direct.',
        'attachment_id' => $old_id,
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Core replacement logic                                             */
/* ------------------------------------------------------------------ */

function dp_toolbox_mr_do_replace( $old_id, $new_id ) {
    // Validate MIME types match
    $old_mime = get_post_mime_type( $old_id );
    $new_mime = get_post_mime_type( $new_id );

    if ( $old_mime !== $new_mime ) {
        return new WP_Error( 'mime_mismatch', sprintf(
            'Bestandstype komt niet overeen: %s → %s',
            $old_mime, $new_mime
        ) );
    }

    // Get new file path
    $new_meta   = wp_get_attachment_metadata( $new_id );
    $upload_dir = wp_upload_dir();

    if ( ! empty( $new_meta['original_image'] ) ) {
        $new_file_path = wp_get_original_image_path( $new_id );
    } else {
        $new_attached_file = get_post_meta( $new_id, '_wp_attached_file', true );
        $new_file_path     = $upload_dir['basedir'] . '/' . $new_attached_file;
    }

    if ( ! $new_file_path || ! file_exists( $new_file_path ) ) {
        return new WP_Error( 'file_missing', 'Nieuw bestand niet gevonden.' );
    }

    // Get old file path
    $old_meta = wp_get_attachment_metadata( $old_id );

    if ( ! empty( $old_meta['original_image'] ) ) {
        $old_file_path = wp_get_original_image_path( $old_id );
    } else {
        $old_attached_file = get_post_meta( $old_id, '_wp_attached_file', true );
        $old_file_path     = $upload_dir['basedir'] . '/' . $old_attached_file;
    }

    // Delete old media files (main + thumbnails)
    dp_toolbox_mr_delete_media_files( $old_id );

    // Ensure target directory exists
    $old_dir = dirname( $old_file_path );
    if ( ! is_dir( $old_dir ) ) {
        wp_mkdir_p( $old_dir );
    }

    // Copy new file to old file location
    if ( ! copy( $new_file_path, $old_file_path ) ) {
        return new WP_Error( 'copy_failed', 'Bestand kon niet gekopieerd worden.' );
    }

    // Regenerate attachment metadata for old attachment
    $new_metadata = wp_generate_attachment_metadata( $old_id, $old_file_path );
    wp_update_attachment_metadata( $old_id, $new_metadata );

    // Delete the temporary new attachment (files + database entries)
    wp_delete_attachment( $new_id, true );

    // Nieuwe versie: vanaf nu dragen alle URL's van deze bijlage ?v=<tijdstip>.
    dp_toolbox_mr_versie_zetten( $old_id, time() );

    return true;
}

/* ------------------------------------------------------------------ */
/*  Delete all files for an attachment (main + thumbnails)             */
/* ------------------------------------------------------------------ */

function dp_toolbox_mr_delete_media_files( $attachment_id ) {
    $meta = wp_get_attachment_metadata( $attachment_id );
    $upload_dir = wp_upload_dir();

    $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
    if ( ! $attached_file ) {
        return;
    }

    $file_path = $upload_dir['basedir'] . '/' . $attached_file;
    $file_dir  = dirname( $file_path );

    // Delete intermediate sizes (thumbnails)
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        foreach ( $meta['sizes'] as $size ) {
            $thumb_path = $file_dir . '/' . $size['file'];
            if ( file_exists( $thumb_path ) ) {
                @unlink( $thumb_path );
            }
        }
    }

    // Delete main file
    if ( file_exists( $file_path ) ) {
        @unlink( $file_path );
    }

    // Delete original image (for large images >2560px)
    if ( ! empty( $meta['original_image'] ) ) {
        $original_path = $file_dir . '/' . $meta['original_image'];
        if ( file_exists( $original_path ) ) {
            @unlink( $original_path );
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Custom success message after replacement                           */
/* ------------------------------------------------------------------ */

add_filter( 'post_updated_messages', 'dp_toolbox_mr_updated_messages' );

function dp_toolbox_mr_updated_messages( $messages ) {
    $messages['attachment'][4] = 'Media bijgewerkt. De website toont de nieuwe versie direct (nieuwe versie-URL, ook voor een CDN).';
    return $messages;
}

/* ------------------------------------------------------------------ */
/*  Versie-URL's (cache busting)                                        */
/*  Het bestand houdt zijn naam, dus een CDN of browser blijft de oude  */
/*  kopie geven (de Hostinger-CDN bewaart afbeeldingen 7 dagen, ook in  */
/*  een privévenster). Daarom krijgen alle URL's van een vervangen      */
/*  bijlage ?v=<tijdstip van vervangen>, in wp-admin én op de website.  */
/*  Blijvend: tot de volgende vervanging is de URL stabiel en dus goed  */
/*  te cachen. Optie dp_toolbox_mr_replaced = [ bijlage-ID => tijd ].   */
/* ------------------------------------------------------------------ */

function dp_toolbox_mr_versie( $attachment_id ) {
    $versies = get_option( 'dp_toolbox_mr_replaced', [] );
    return ( is_array( $versies ) && isset( $versies[ (int) $attachment_id ] ) ) ? (int) $versies[ (int) $attachment_id ] : 0;
}

function dp_toolbox_mr_met_versie( $url, $attachment_id ) {
    $versie = dp_toolbox_mr_versie( $attachment_id );
    return ( $versie && is_string( $url ) && $url !== '' ) ? add_query_arg( 'v', $versie, $url ) : $url;
}

/** Na een vervanging: versie vastleggen, opgeslagen URL's bijwerken, paginacache legen. */
function dp_toolbox_mr_versie_zetten( $attachment_id, $tijd ) {
    $versies = get_option( 'dp_toolbox_mr_replaced', [] );
    $versies = is_array( $versies ) ? $versies : [];
    $versies[ (int) $attachment_id ] = (int) $tijd;
    foreach ( array_keys( $versies ) as $id ) {
        if ( get_post_type( $id ) !== 'attachment' ) {
            unset( $versies[ $id ] ); // verwijderde bijlagen
        }
    }
    update_option( 'dp_toolbox_mr_replaced', $versies, false );

    dp_toolbox_mr_opgeslagen_urls( $attachment_id );

    // Gecachte pagina's bevatten nog de URL zonder (nieuwe) versie.
    do_action( 'litespeed_purge_all' );
}

add_filter( 'wp_get_attachment_url', 'dp_toolbox_mr_met_versie', 10, 2 );

add_filter( 'wp_get_attachment_image_src', function ( $image, $attachment_id ) {
    if ( is_array( $image ) && ! empty( $image[0] ) ) {
        $image[0] = dp_toolbox_mr_met_versie( $image[0], $attachment_id );
    }
    return $image;
}, 10, 2 );

add_filter( 'wp_calculate_image_srcset', function ( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
    if ( is_array( $sources ) && dp_toolbox_mr_versie( $attachment_id ) ) {
        foreach ( $sources as &$bron ) {
            $bron['url'] = dp_toolbox_mr_met_versie( $bron['url'], $attachment_id );
        }
        unset( $bron );
    }
    return $sources;
}, 10, 5 );

add_filter( 'wp_prepare_attachment_for_js', function ( $response, $attachment ) {
    if ( ! dp_toolbox_mr_versie( $attachment->ID ) ) {
        return $response;
    }
    $response['url'] = dp_toolbox_mr_met_versie( $response['url'] ?? '', $attachment->ID );
    if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
        foreach ( $response['sizes'] as &$maat ) {
            $maat['url'] = dp_toolbox_mr_met_versie( $maat['url'] ?? '', $attachment->ID );
        }
        unset( $maat );
    }
    return $response;
}, 10, 2 );

/* Afbeeldingen in de paginatekst hebben een vaste src: die (en de srcset) ook van een versie voorzien. */
add_filter( 'wp_content_img_tag', function ( $html, $context, $attachment_id ) {
    $versie = $attachment_id ? dp_toolbox_mr_versie( $attachment_id ) : 0;
    if ( ! $versie ) {
        return $html;
    }
    $basis = pathinfo( wp_basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ), PATHINFO_FILENAME );
    if ( $basis === '' ) {
        return $html;
    }
    return preg_replace_callback(
        '#(https?://[^\s"\',]*/' . preg_quote( $basis, '#' ) . '(?:-\d+x\d+|-scaled)?\.[a-z0-9]+)(\?[^\s"\',]*)?#i',
        fn( $m ) => add_query_arg( 'v', $versie, $m[1] . ( $m[2] ?? '' ) ),
        $html
    );
}, 10, 3 );

/**
 * FluentCart bewaart productfoto's als volledige URL (galerij, standaardfoto, per variant),
 * niet alleen als ID. Die opgeslagen URL's krijgen de versie mee, anders toont de productpagina
 * de oude kopie uit de CDN.
 */
function dp_toolbox_mr_opgeslagen_urls( $attachment_id ) {
    global $wpdb;
    $bestand = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
    if ( $bestand === '' || ! dp_toolbox_mr_versie( $attachment_id ) ) {
        return 0;
    }
    $url  = wp_get_attachment_url( $attachment_id ); // al met ?v=
    $zoek = '%' . $wpdb->esc_like( wp_basename( $bestand ) ) . '%';
    $zet  = function ( $data ) use ( &$zet, $attachment_id, $url ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }
        if ( isset( $data['id'], $data['url'] ) && (int) $data['id'] === (int) $attachment_id ) {
            $data['url'] = $url;
        }
        foreach ( $data as $sleutel => $waarde ) {
            if ( is_array( $waarde ) ) {
                $data[ $sleutel ] = $zet( $waarde );
            }
        }
        return $data;
    };
    $n = 0;

    // Galerij (post meta)
    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'fluent-products-gallery-image' AND meta_value LIKE %s", $zoek ) ) as $rij ) {
        $oud   = maybe_unserialize( $rij->meta_value );
        $nieuw = $zet( $oud );
        if ( $nieuw !== $oud ) {
            update_metadata_by_mid( 'post', (int) $rij->meta_id, $nieuw );
            $n++;
        }
    }

    // FluentCart-tabellen (JSON), alleen als ze bestaan
    $tabellen = [
        $wpdb->prefix . 'fct_product_details' => [ 'id', 'default_media', '' ],
        $wpdb->prefix . 'fct_product_meta'    => [ 'id', 'meta_value', "AND meta_key = 'product_thumbnail'" ],
    ];
    foreach ( $tabellen as $tabel => [ $sleutel, $kolom, $extra ] ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabel ) ) !== $tabel ) {
            continue;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabel- en kolomnamen staan hierboven vast
        foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT {$sleutel} AS k, {$kolom} AS v FROM {$tabel} WHERE {$kolom} LIKE %s {$extra}", $zoek ) ) as $rij ) {
            $oud = json_decode( (string) $rij->v, true );
            if ( ! is_array( $oud ) ) {
                continue;
            }
            $nieuw = $zet( $oud );
            if ( $nieuw !== $oud ) {
                $wpdb->update( $tabel, [ $kolom => wp_json_encode( $nieuw ) ], [ $sleutel => $rij->k ] );
                $n++;
            }
        }
    }
    return $n;
}

/*
 * Eenmalig na de update naar 1.1.0: bijlagen die al eerder vervangen waren, krijgen hun
 * opgeslagen URL's alsnog bijgewerkt (vóór 1.1.0 kregen alleen de admin-URL's een versie).
 */
add_action( 'init', function () {
    if ( get_option( 'dp_toolbox_mr_versie_urls' ) === '1.1.0' ) {
        return;
    }
    update_option( 'dp_toolbox_mr_versie_urls', '1.1.0', false );
    $versies = get_option( 'dp_toolbox_mr_replaced', [] );
    foreach ( is_array( $versies ) ? array_keys( $versies ) : [] as $id ) {
        if ( get_post_type( $id ) === 'attachment' ) {
            dp_toolbox_mr_opgeslagen_urls( (int) $id );
        }
    }
    if ( $versies ) {
        do_action( 'litespeed_purge_all' );
    }
}, 20 );
