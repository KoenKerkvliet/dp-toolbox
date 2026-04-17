<?php
/**
 * Module Name: Mediacategorieën
 * Description: Organiseer mediabestanden met categorieën — filter in lijst- en rasterweergave.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Register taxonomy                                                  */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
    register_taxonomy( 'dp_media_category', 'attachment', [
        'labels' => [
            'name'              => 'Mediacategorieën',
            'singular_name'     => 'Mediacategorie',
            'search_items'      => 'Categorieën zoeken',
            'all_items'         => 'Alle categorieën',
            'parent_item'       => 'Bovenliggende categorie',
            'parent_item_colon' => 'Bovenliggende categorie:',
            'edit_item'         => 'Categorie bewerken',
            'update_item'       => 'Categorie bijwerken',
            'add_new_item'      => 'Nieuwe categorie',
            'new_item_name'     => 'Nieuwe categorienaam',
            'menu_name'         => 'Mediacategorieën',
            'not_found'         => 'Geen categorieën gevonden.',
        ],
        'hierarchical'          => true,
        'show_ui'               => true,
        'show_in_menu'          => 'upload.php',
        'show_in_rest'          => true,
        'show_admin_column'     => true,
        'query_var'             => true,
        'rewrite'               => false,
        'update_count_callback' => '_update_generic_term_count',
    ] );
}, 20 );

/* ------------------------------------------------------------------ */
/*  Enqueue assets                                                     */
/* ------------------------------------------------------------------ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'upload.php' ], true ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    if ( $hook === 'upload.php' || ( $hook === 'post.php' && $screen->post_type === 'attachment' ) ) {
        $module_url = DP_TOOLBOX_URL . 'modules/media-categories/assets/';

        wp_enqueue_style(
            'dp-toolbox-media-categories',
            $module_url . 'media-categories.css',
            [],
            DP_TOOLBOX_VERSION
        );

        wp_enqueue_script(
            'dp-toolbox-media-categories',
            $module_url . 'media-categories.js',
            [ 'jquery', 'media-views' ],
            DP_TOOLBOX_VERSION,
            true
        );

        // Pass terms to JS for grid filter
        $terms = get_terms( [
            'taxonomy'   => 'dp_media_category',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );

        $term_data = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $term_data[] = [
                    'id'     => $t->term_id,
                    'name'   => $t->name,
                    'slug'   => $t->slug,
                    'parent' => $t->parent,
                    'count'  => $t->count,
                ];
            }
        }

        wp_localize_script( 'dp-toolbox-media-categories', 'dpMC', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dp_toolbox_media_categories' ),
            'taxonomy' => 'dp_media_category',
            'terms'    => $term_data,
            'allLabel' => 'Alle categorieën',
        ] );
    }
} );

/* ------------------------------------------------------------------ */
/*  List view — filter dropdown                                        */
/* ------------------------------------------------------------------ */

add_action( 'restrict_manage_posts', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'upload' ) {
        return;
    }

    $selected = absint( $_GET['dp_media_category'] ?? 0 );

    wp_dropdown_categories( [
        'taxonomy'        => 'dp_media_category',
        'name'            => 'dp_media_category',
        'show_option_all' => 'Alle categorieën',
        'hide_empty'      => false,
        'hierarchical'    => true,
        'orderby'         => 'name',
        'selected'        => $selected,
        'value_field'     => 'term_id',
    ] );
} );

add_action( 'pre_get_posts', function ( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'upload' ) {
        return;
    }

    $term_id = absint( $_GET['dp_media_category'] ?? 0 );
    if ( $term_id > 0 ) {
        $query->set( 'tax_query', [ [
            'taxonomy' => 'dp_media_category',
            'field'    => 'term_id',
            'terms'    => $term_id,
        ] ] );
    }
} );

/* ------------------------------------------------------------------ */
/*  Grid view — filter via AJAX query                                  */
/* ------------------------------------------------------------------ */

add_filter( 'ajax_query_attachments_args', function ( $query ) {
    $term_id = absint( $query['dp_media_category'] ?? 0 );

    // Remove the raw key — WP_Query would interpret it as taxonomy slug lookup
    // (searching for a term with slug "11"), which always fails → 0 = 1.
    unset( $query['dp_media_category'] );

    if ( $term_id > 0 ) {
        $query['tax_query'] = [ [
            'taxonomy' => 'dp_media_category',
            'field'    => 'term_id',
            'terms'    => $term_id,
        ] ];
    }

    return $query;
} );

/* ------------------------------------------------------------------ */
/*  Attachment fields — category checkboxes                            */
/* ------------------------------------------------------------------ */

add_filter( 'attachment_fields_to_edit', 'dp_toolbox_mc_attachment_fields', 10, 2 );

function dp_toolbox_mc_attachment_fields( $fields, $post ) {
    if ( ! current_user_can( 'upload_files' ) ) {
        return $fields;
    }

    $all_terms = get_terms( [
        'taxonomy'   => 'dp_media_category',
        'hide_empty' => false,
        'orderby'    => 'name',
    ] );

    if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
        $fields['dp_media_category'] = [
            'label' => 'Categorieën',
            'input' => 'html',
            'html'  => '<p class="dp-mc-empty">Nog geen categorieën aangemaakt.</p>',
        ];
        return $fields;
    }

    $assigned = wp_get_object_terms( $post->ID, 'dp_media_category', [ 'fields' => 'ids' ] );
    if ( is_wp_error( $assigned ) ) {
        $assigned = [];
    }

    ob_start();
    ?>
    <div class="dp-mc-checkboxes" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>">
        <?php dp_toolbox_mc_render_term_tree( $all_terms, $assigned, 0 ); ?>
    </div>
    <?php
    $html = ob_get_clean();

    $fields['dp_media_category'] = [
        'label' => 'Categorieën',
        'input' => 'html',
        'html'  => $html,
    ];

    return $fields;
}

/**
 * Recursively render checkbox tree for hierarchical terms.
 */
function dp_toolbox_mc_render_term_tree( $terms, $assigned, $parent_id, $depth = 0 ) {
    $children = array_filter( $terms, function ( $t ) use ( $parent_id ) {
        return (int) $t->parent === (int) $parent_id;
    } );

    if ( empty( $children ) ) {
        return;
    }

    foreach ( $children as $term ) {
        $checked = in_array( $term->term_id, $assigned, true ) ? 'checked' : '';
        $indent  = $depth > 0 ? ' style="margin-left:' . ( $depth * 18 ) . 'px"' : '';
        ?>
        <label class="dp-mc-term"<?php echo $indent; ?>>
            <input type="checkbox"
                   data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
                   <?php echo $checked; ?>>
            <?php echo esc_html( $term->name ); ?>
        </label>
        <?php
        dp_toolbox_mc_render_term_tree( $terms, $assigned, $term->term_id, $depth + 1 );
    }
}

/* ------------------------------------------------------------------ */
/*  AJAX — save categories for an attachment                           */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_save_media_categories', function () {
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( 'Geen toestemming.' );
    }

    check_ajax_referer( 'dp_toolbox_media_categories', 'nonce' );

    $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
    if ( ! $attachment_id ) {
        wp_send_json_error( 'Ongeldig attachment ID.' );
    }

    $terms = [];
    if ( ! empty( $_POST['terms'] ) && is_array( $_POST['terms'] ) ) {
        $terms = array_map( 'absint', $_POST['terms'] );
        $terms = array_filter( $terms );
    }

    wp_set_object_terms( $attachment_id, $terms, 'dp_media_category' );

    wp_send_json_success( [
        'message' => 'Categorieën opgeslagen.',
    ] );
} );
