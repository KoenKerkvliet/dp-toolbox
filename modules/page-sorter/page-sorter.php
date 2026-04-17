<?php
/**
 * Module Name: Page Sorter
 * Description: Sorteer pagina's via drag & drop in het paginaoverzicht.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Default ordering by menu_order                                     */
/* ------------------------------------------------------------------ */

add_action( 'pre_get_posts', function ( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'page' ) {
        return;
    }
    // Only apply when no explicit orderby is set by user
    if ( ! isset( $_GET['orderby'] ) ) {
        $query->set( 'orderby', 'menu_order' );
        $query->set( 'order', 'ASC' );
    }
} );

/* ------------------------------------------------------------------ */
/*  Enqueue sortable scripts on Pages list                             */
/* ------------------------------------------------------------------ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'edit.php' ) return;
    if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'page' ) {
        // Also trigger on default post_type=post screen which shows pages
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'page' ) return;
    }
    // Don't enable sorting when user has clicked a column header to sort
    if ( isset( $_GET['orderby'] ) ) return;

    wp_enqueue_script( 'jquery-ui-sortable' );
} );

/* ------------------------------------------------------------------ */
/*  AJAX handler to save new order                                     */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_save_page_order', function () {
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    check_ajax_referer( 'dp_toolbox_page_sorter', 'nonce' );

    $order = isset( $_POST['order'] ) ? $_POST['order'] : [];
    if ( ! is_array( $order ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    global $wpdb;
    foreach ( $order as $position => $post_id ) {
        $wpdb->update(
            $wpdb->posts,
            [ 'menu_order' => (int) $position ],
            [ 'ID' => (int) $post_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    wp_send_json_success();
} );

/* ------------------------------------------------------------------ */
/*  Inline JS & CSS for drag-and-drop                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_footer-edit.php', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'page' ) return;
    if ( isset( $_GET['orderby'] ) ) return;

    $nonce = wp_create_nonce( 'dp_toolbox_page_sorter' );
    ?>
    <style>
        .dp-sortable-active .type-page {
            cursor: grab;
        }
        .dp-sortable-active .type-page:active {
            cursor: grabbing;
        }
        .type-page.ui-sortable-helper {
            background: #fff;
            box-shadow: 0 4px 16px rgba(40, 30, 93, 0.15);
            border-left: 4px solid #281E5D;
        }
        .type-page.ui-sortable-placeholder {
            visibility: visible !important;
            background: #f0ecff;
            border: 2px dashed #281E5D;
        }
        .type-page.ui-sortable-placeholder td {
            visibility: hidden;
        }
        .dp-sort-notice {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0ecff;
            color: #281E5D;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            margin-left: 12px;
        }
        .dp-sort-notice .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        .dp-sort-saving {
            background: #fff8e5;
            color: #996800;
        }
        .dp-sort-saved {
            background: #edfaef;
            color: #00a32a;
        }
    </style>
    <script>
    jQuery(function($) {
        var $tbody = $('#the-list');
        if ( ! $tbody.length ) return;

        // Add notice next to heading
        var $heading = $('.wp-heading-inline');
        var $notice  = $('<span class="dp-sort-notice"><span class="dashicons dashicons-move"></span> Sleep pagina\'s om te sorteren</span>');
        $heading.after($notice);

        $tbody.closest('table').addClass('dp-sortable-active');

        $tbody.sortable({
            items:       '> tr',
            axis:        'y',
            handle:      'td',
            placeholder: 'type-page ui-sortable-placeholder',
            tolerance:   'pointer',
            opacity:     0.85,
            cursor:      'grabbing',

            start: function(e, ui) {
                // Set placeholder height to match dragged row
                ui.placeholder.height(ui.helper.outerHeight());
                // Copy column widths so helper doesn't collapse
                ui.helper.find('td').each(function(i) {
                    $(this).width($(this).width());
                });
            },

            update: function() {
                $notice
                    .text('Opslaan...')
                    .removeClass('dp-sort-saved')
                    .addClass('dp-sort-saving');

                var order = [];
                $tbody.find('tr').each(function() {
                    var id = $(this).attr('id');
                    if (id) {
                        order.push(id.replace('post-', ''));
                    }
                });

                $.post(ajaxurl, {
                    action: 'dp_toolbox_save_page_order',
                    nonce:  '<?php echo $nonce; ?>',
                    order:  order
                }, function(response) {
                    if (response.success) {
                        $notice
                            .html('<span class="dashicons dashicons-yes-alt"></span> Volgorde opgeslagen')
                            .removeClass('dp-sort-saving')
                            .addClass('dp-sort-saved');
                        setTimeout(function() {
                            $notice
                                .html('<span class="dashicons dashicons-move"></span> Sleep pagina\'s om te sorteren')
                                .removeClass('dp-sort-saved');
                        }, 2000);
                    }
                });
            }
        });
    });
    </script>
    <?php
} );