<?php
/**
 * DP Toolbox — Gutenberg Toggle Admin Page (Tab)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* Register the setting */
add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_gt_settings', 'dp_toolbox_gt_disabled_post_types', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'sanitize_key', $input ) : [];
        },
        'default' => [],
    ] );
} );

/* Inline tab on Modules screen */
add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'gutenberg-toggle', 'dp_toolbox_gt_admin_render_inline', [
            'title'       => 'Gutenberg Toggle',
            'description' => 'Schakel de block-editor per post-type uit. Geselecteerde types vallen terug op de Classic Editor.',
        ] );
    }
} );

function dp_toolbox_gt_admin_render_inline() {
    // All UI-visible post-types that support an editor (anders is Gutenberg-toggle betekenisloos)
    $post_types = get_post_types( [ 'show_ui' => true ], 'objects' );
    $relevant   = array_filter( $post_types, function ( $pt ) {
        if ( in_array( $pt->name, [ 'attachment' ], true ) ) {
            return false;
        }
        return post_type_supports( $pt->name, 'editor' );
    } );

    // Sorteer: built-in eerst (post, page), dan custom op label
    uasort( $relevant, function ( $a, $b ) {
        if ( $a->_builtin !== $b->_builtin ) {
            return $a->_builtin ? -1 : 1;
        }
        return strcasecmp( $a->labels->singular_name ?? $a->label, $b->labels->singular_name ?? $b->label );
    } );

    $disabled = dp_toolbox_gt_get_disabled();
    ?>
    <style>
        .dp-gt-intro {
            background: #f6f7f7; border: 1px solid #e5e7eb; border-radius: 8px;
            padding: 14px 18px; margin-bottom: 18px; font-size: 13px; color: #50575e; line-height: 1.5;
        }
        .dp-gt-intro strong { color: #1d2327; }
        .dp-gt-legend { display: flex; gap: 20px; margin-bottom: 16px; font-size: 12px; color: #666; }
        .dp-gt-legend span { display: flex; align-items: center; gap: 6px; }
        .dp-gt-legend .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
        .dp-gt-legend .dot.green { background: #00a32a; }
        .dp-gt-legend .dot.red { background: #d63638; }
        .dp-gt-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 18px; margin-bottom: 8px;
            display: flex; align-items: center; gap: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-gt-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-gt-card.is-disabled { border-left: 4px solid #d63638; background: #fef7f7; }
        .dp-gt-toggle input[type="checkbox"] { display: none; }
        .dp-gt-toggle label {
            display: block; width: 42px; height: 22px; background: #00a32a;
            border-radius: 11px; position: relative; cursor: pointer; transition: background 0.2s; flex-shrink: 0;
        }
        .dp-gt-toggle label::after {
            content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;
            background: #fff; border-radius: 50%; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .dp-gt-toggle input:checked + label { background: #d63638; }
        .dp-gt-toggle input:checked + label::after { transform: translateX(20px); }
        .dp-gt-info { flex: 1; min-width: 0; }
        .dp-gt-info h3 { margin: 0 0 3px; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-gt-info p { margin: 0; color: #666; font-size: 11.5px; line-height: 1.4; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .dp-gt-info .slug { font-family: ui-monospace, SFMono-Regular, "Cascadia Code", Menlo, monospace; font-size: 11px; color: #888; background: #f0f0f1; padding: 1px 6px; border-radius: 3px; }
        .dp-gt-info .builtin { color: #2271b1; font-size: 11px; font-weight: 500; }
        .dp-gt-status { flex-shrink: 0; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 4px; }
        .dp-gt-status.on { color: #00a32a; background: #edfaef; }
        .dp-gt-status.off { color: #d63638; background: #fce9e9; }
        .dp-gt-empty { padding: 24px; background: #f7f7f7; border-radius: 8px; color: #666; text-align: center; }
    </style>

    <div class="dp-gt-intro">
        <strong>Tip:</strong> wanneer Gutenberg uit staat voor een post-type, gebruikt WordPress de Classic Editor. Meta-velden van plugins zoals JetEngine, ACF en Meta Box verschijnen dan direct onder de titel in plaats van ingeklapt onderaan de pagina. Handig voor CPTs die vooral uit custom velden bestaan en geen rijke content nodig hebben.
    </div>

    <div class="dp-gt-legend">
        <span><span class="dot green"></span> Gutenberg actief (block-editor)</span>
        <span><span class="dot red"></span> Gutenberg uit (Classic Editor)</span>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_gt_settings' ); ?>

        <?php if ( empty( $relevant ) ) : ?>
            <div class="dp-gt-empty">
                Geen post-types gevonden met editor-ondersteuning.
            </div>
        <?php else : ?>
            <?php foreach ( $relevant as $pt ) :
                $is_off = in_array( $pt->name, $disabled, true );
                $label  = $pt->labels->singular_name ?? $pt->label;
            ?>
                <div class="dp-gt-card <?php echo $is_off ? 'is-disabled' : ''; ?>">
                    <div class="dp-gt-toggle">
                        <input type="checkbox" id="dp-gt-<?php echo esc_attr( $pt->name ); ?>" name="dp_toolbox_gt_disabled_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( $is_off ); ?>>
                        <label for="dp-gt-<?php echo esc_attr( $pt->name ); ?>"></label>
                    </div>
                    <div class="dp-gt-info">
                        <h3><?php echo esc_html( $label ); ?></h3>
                        <p>
                            <span class="slug"><?php echo esc_html( $pt->name ); ?></span>
                            <?php if ( $pt->_builtin ) : ?>
                                <span class="builtin">WordPress-core</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="dp-gt-status <?php echo $is_off ? 'off' : 'on'; ?>">
                        <?php echo $is_off ? 'Classic' : 'Gutenberg'; ?>
                    </span>
                </div>
            <?php endforeach; ?>

            <?php submit_button(); ?>
        <?php endif; ?>
    </form>
    <?php
}
