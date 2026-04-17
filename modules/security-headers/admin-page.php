<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_sh_settings', 'dp_toolbox_security_headers', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'sanitize_key', $input ) : [];
        },
        'default' => [],
    ] );
} );

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Security Headers',
        'Security Headers',
        'manage_options',
        'dp-toolbox-security-headers',
        'dp_toolbox_sh_page'
    );
} );

function dp_toolbox_sh_page() {
    $headers = dp_toolbox_sh_get_available_headers();
    $enabled = dp_toolbox_sh_get_enabled();

    dp_toolbox_page_start( 'Security Headers', 'Voeg HTTP-beveiligingsheaders toe om je site beter te beschermen.' );
    ?>
    <style>
        .dp-sh-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 18px; margin-bottom: 8px;
            display: flex; align-items: center; gap: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-sh-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-sh-card.is-on { border-left: 4px solid #00a32a; }
        .dp-sh-card.is-off { border-left: 4px solid #ccc; opacity: 0.6; }
        .dp-sh-card.is-off:hover { opacity: 1; }
        .dp-sh-info { flex: 1; min-width: 0; }
        .dp-sh-info h3 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-sh-info p { margin: 0; color: #666; font-size: 12px; line-height: 1.4; }
        .dp-sh-value { font-size: 11px; color: #281E5D; background: #f0ecff; padding: 3px 8px; border-radius: 4px; font-family: monospace; }
        .dp-sh-status { flex-shrink: 0; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 3px 8px; border-radius: 4px; }
        .dp-sh-status.on { color: #00a32a; background: #edfaef; }
        .dp-sh-status.off { color: #999; background: #f5f5f5; }
    </style>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_sh_settings' ); ?>

        <?php foreach ( $headers as $key => $header ) :
            $is_on = in_array( $key, $enabled, true );
        ?>
            <div class="dp-sh-card <?php echo $is_on ? 'is-on' : 'is-off'; ?>">
                <div class="dp-toggle">
                    <input type="checkbox"
                           id="dp-sh-<?php echo esc_attr( $key ); ?>"
                           name="dp_toolbox_security_headers[]"
                           value="<?php echo esc_attr( $key ); ?>"
                           <?php checked( $is_on ); ?>>
                    <label for="dp-sh-<?php echo esc_attr( $key ); ?>"></label>
                </div>
                <div class="dp-sh-info">
                    <h3><?php echo esc_html( $header['label'] ); ?></h3>
                    <p><?php echo esc_html( $header['desc'] ); ?></p>
                    <code class="dp-sh-value"><?php echo esc_html( $header['value'] ); ?></code>
                </div>
                <span class="dp-sh-status <?php echo $is_on ? 'on' : 'off'; ?>">
                    <?php echo $is_on ? 'Actief' : 'Uit'; ?>
                </span>
            </div>
        <?php endforeach; ?>

        <?php submit_button( 'Opslaan' ); ?>
    </form>
    <?php
    dp_toolbox_page_end();
}
