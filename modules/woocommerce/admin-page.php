<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_wc_settings', 'dp_toolbox_wc_features', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            if ( ! is_array( $input ) ) return [];
            return array_values( array_map( 'sanitize_key', $input ) );
        },
        'default' => [],
    ] );
} );

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'woocommerce', 'dp_toolbox_wc_render_inline', [
            'title'       => 'WooCommerce-features',
            'description' => 'Selecteer welke WooCommerce-uitbreidingen op deze site actief moeten zijn.',
        ] );
    }
} );

function dp_toolbox_wc_render_inline() {
    $features = dp_toolbox_wc_get_available_features();
    $enabled  = dp_toolbox_wc_get_enabled_features();
    $wc_active = class_exists( 'WooCommerce' );
    ?>
    <style>
        .dp-wc-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 18px; margin-bottom: 8px;
            display: flex; align-items: center; gap: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-wc-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-wc-card.is-on  { border-left: 4px solid #00a32a; }
        .dp-wc-card.is-off { border-left: 4px solid #ccc; opacity: 0.6; }
        .dp-wc-card.is-off:hover { opacity: 1; }
        .dp-wc-info { flex: 1; min-width: 0; }
        .dp-wc-info h3 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-wc-info p  { margin: 0; color: #666; font-size: 12px; line-height: 1.4; }
        .dp-wc-version { font-size: 11px; color: #281E5D; background: #f0ecff; padding: 3px 8px; border-radius: 4px; font-family: monospace; }
        .dp-wc-status  { flex-shrink: 0; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 3px 8px; border-radius: 4px; }
        .dp-wc-status.on  { color: #00a32a; background: #edfaef; }
        .dp-wc-status.off { color: #999; background: #f5f5f5; }
        .dp-wc-notice {
            margin: 0 0 16px; padding: 10px 14px;
            background: #fcf8e3; border-left: 4px solid #f0b849;
            font-size: 12px; color: #5a4a00;
        }
    </style>

    <?php if ( ! $wc_active ) : ?>
        <div class="dp-wc-notice">
            WooCommerce is niet actief op deze site. Je kunt features alvast aanzetten, maar ze doen pas iets zodra WooCommerce geactiveerd wordt.
        </div>
    <?php endif; ?>

    <?php if ( empty( $features ) ) : ?>
        <p><em>Geen features gevonden in <code>modules/woocommerce/features/</code>.</em></p>
        <?php return; ?>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_wc_settings' ); ?>

        <?php foreach ( $features as $slug => $feature ) :
            $is_on = in_array( $slug, $enabled, true );
        ?>
            <div class="dp-wc-card <?php echo $is_on ? 'is-on' : 'is-off'; ?>">
                <label class="dp-toggle" style="flex-shrink:0">
                    <input type="checkbox"
                           name="dp_toolbox_wc_features[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                           <?php checked( $is_on ); ?> />
                </label>
                <div class="dp-wc-info">
                    <h3><?php echo esc_html( $feature['name'] ); ?></h3>
                    <p><?php echo esc_html( $feature['description'] ); ?></p>
                </div>
                <?php if ( $feature['version'] !== '' ) : ?>
                    <span class="dp-wc-version">v<?php echo esc_html( $feature['version'] ); ?></span>
                <?php endif; ?>
                <span class="dp-wc-status <?php echo $is_on ? 'on' : 'off'; ?>">
                    <?php echo $is_on ? 'Aan' : 'Uit'; ?>
                </span>
            </div>
        <?php endforeach; ?>

        <p style="margin-top: 16px;">
            <?php submit_button( 'Wijzigingen opslaan', 'primary', 'submit', false ); ?>
        </p>
    </form>
    <?php
}
