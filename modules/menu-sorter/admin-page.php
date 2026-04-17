<?php
/**
 * DP Toolbox — Menu Sorter Admin Page (Tab)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Menu Sorter',
        'Menu Sorter',
        'manage_options',
        'dp-toolbox-menu-sorter',
        'dp_toolbox_menu_sorter_page'
    );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'dp-toolbox_page_dp-toolbox-menu-sorter' ) return;
    wp_enqueue_script( 'jquery-ui-sortable' );
} );

function dp_toolbox_menu_sorter_page() {
    $items       = get_transient( 'dp_toolbox_admin_menu_items' );
    $saved_order = get_option( 'dp_toolbox_menu_order', [] );
    $nonce       = wp_create_nonce( 'dp_toolbox_menu_sorter' );
    dp_toolbox_page_start( 'Menu Sorter', 'Sleep menu-items naar de gewenste positie.' );

    // Sort items by saved order if available
    if ( ! empty( $saved_order ) && ! empty( $items ) ) {
        $slug_map = [];
        foreach ( $items as $item ) {
            $slug_map[ $item['slug'] ] = $item;
        }
        $sorted = [];
        foreach ( $saved_order as $slug ) {
            if ( isset( $slug_map[ $slug ] ) ) {
                $sorted[] = $slug_map[ $slug ];
                unset( $slug_map[ $slug ] );
            }
        }
        // Append any new items not in saved order
        foreach ( $slug_map as $item ) {
            $sorted[] = $item;
        }
        $items = $sorted;
    }
    ?>
    <style>
        .dp-ms-intro { margin-top: 0; color: #666; font-size: 13px; margin-bottom: 16px; }

        .dp-ms-toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            align-items: center;
        }

        .dp-ms-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .dp-ms-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px 16px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: grab;
            transition: border-color 0.2s, box-shadow 0.2s;
            user-select: none;
        }
        .dp-ms-item:hover {
            border-color: #281E5D;
            box-shadow: 0 2px 8px rgba(40,30,93,0.08);
        }
        .dp-ms-item:active { cursor: grabbing; }

        .dp-ms-item.ui-sortable-helper {
            background: #fff;
            box-shadow: 0 4px 16px rgba(40,30,93,0.15);
            border-left: 4px solid #281E5D;
        }
        .dp-ms-item.ui-sortable-placeholder {
            visibility: visible !important;
            background: #f0ecff;
            border: 2px dashed #281E5D;
            border-radius: 6px;
            height: 42px;
        }

        .dp-ms-item.is-separator {
            background: #f9f9f9;
            border-style: dashed;
            justify-content: center;
            padding: 6px 16px;
        }

        .dp-ms-grip {
            color: #ccc;
            flex-shrink: 0;
        }
        .dp-ms-grip .dashicons { font-size: 18px; width: 18px; height: 18px; }

        .dp-ms-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
        }
        .dp-ms-icon .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            color: #666;
        }
        .dp-ms-icon img {
            width: 20px;
            height: 20px;
            display: block;
        }

        .dp-ms-label {
            flex: 1;
            font-size: 13px;
            font-weight: 500;
            color: #1d2327;
        }
        .dp-ms-item.is-separator .dp-ms-label {
            color: #999;
            font-size: 12px;
            font-style: italic;
            font-weight: 400;
        }

        .dp-ms-slug {
            font-size: 11px;
            color: #bbb;
            flex-shrink: 0;
        }

        .dp-ms-notice {
            display: none;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            margin-left: auto;
        }
        .dp-ms-notice.saving { background: #fff8e5; color: #996800; display: inline-flex; }
        .dp-ms-notice.saved { background: #edfaef; color: #00a32a; display: inline-flex; }

        .dp-ms-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dp-ms-btn-primary { background: #281E5D; color: #fff; }
        .dp-ms-btn-primary:hover { background: #4a3a8a; }
        .dp-ms-btn-secondary { background: #fff; color: #281E5D; border: 1px solid #ddd; }
        .dp-ms-btn-secondary:hover { border-color: #281E5D; }

        .dp-ms-empty {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 32px;
            text-align: center;
            color: #666;
        }
    </style>

    <p class="dp-ms-intro">Sleep menu-items naar de gewenste positie. De volgorde wordt automatisch opgeslagen.</p>

    <div class="dp-ms-toolbar">
        <button id="dp-ms-reset" class="dp-ms-btn dp-ms-btn-secondary">
            <span class="dashicons dashicons-undo" style="line-height:1.3;"></span> Herstellen
        </button>
        <span id="dp-ms-notice" class="dp-ms-notice"></span>
    </div>

    <?php if ( empty( $items ) ) : ?>
        <div class="dp-ms-empty">
            <p>Nog geen menu-items gedetecteerd. Ververs een willekeurige admin-pagina en kom terug.</p>
        </div>
    <?php else : ?>
        <ul id="dp-ms-list" class="dp-ms-list">
            <?php foreach ( $items as $item ) :
                $is_sep = ! empty( $item['separator'] );
                $icon   = $item['icon'] ?? '';
            ?>
                <li class="dp-ms-item <?php echo $is_sep ? 'is-separator' : ''; ?>" data-slug="<?php echo esc_attr( $item['slug'] ); ?>">
                    <span class="dp-ms-grip"><span class="dashicons dashicons-menu"></span></span>
                    <?php if ( ! $is_sep ) : ?>
                        <span class="dp-ms-icon">
                            <?php if ( $icon && strpos( $icon, 'dashicons-' ) === 0 ) : ?>
                                <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                            <?php elseif ( $icon && strpos( $icon, 'http' ) === 0 ) : ?>
                                <img src="<?php echo esc_url( $icon ); ?>" alt="">
                            <?php elseif ( $icon === 'none' || $icon === '' ) : ?>
                                <span class="dashicons dashicons-admin-generic"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-admin-generic"></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <span class="dp-ms-label"><?php echo esc_html( $item['title'] ); ?></span>
                    <?php if ( ! $is_sep ) : ?>
                        <span class="dp-ms-slug"><?php echo esc_html( $item['slug'] ); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <script>
    jQuery(function($) {
        var $list   = $('#dp-ms-list');
        var $notice = $('#dp-ms-notice');
        var ajaxUrl = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
        var nonce   = '<?php echo $nonce; ?>';

        if ( ! $list.length ) return;

        $list.sortable({
            axis:        'y',
            handle:      '.dp-ms-grip',
            placeholder: 'dp-ms-item ui-sortable-placeholder',
            tolerance:   'pointer',
            opacity:     0.85,
            cursor:      'grabbing',

            start: function(e, ui) {
                ui.placeholder.height(ui.helper.outerHeight() - 4);
            },

            update: function() {
                $notice.text('Opslaan...').removeClass('saved').addClass('saving');

                var order = [];
                $list.find('.dp-ms-item').each(function() {
                    order.push($(this).data('slug'));
                });

                $.post(ajaxUrl, {
                    action: 'dp_toolbox_save_menu_order',
                    nonce: nonce,
                    order: order
                }, function(res) {
                    if (res.success) {
                        $notice.text('Volgorde opgeslagen — ververs de pagina om het te zien')
                               .removeClass('saving').addClass('saved');
                    }
                });
            }
        });

        $('#dp-ms-reset').on('click', function() {
            if (!confirm('Weet je zeker dat je de menusortering wilt herstellen naar de standaard?')) return;
            $.post(ajaxUrl, {
                action: 'dp_toolbox_reset_menu_order',
                nonce: nonce
            }, function(res) {
                if (res.success) {
                    $notice.text('Standaardvolgorde hersteld — ververs de pagina')
                           .removeClass('saving').addClass('saved');
                }
            });
        });
    });
    </script>
    <?php
    dp_toolbox_page_end();
}