<?php
/**
 * DP Toolbox — Disable Features Admin Page (Tab)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_df_settings', 'dp_toolbox_disabled_features', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'sanitize_key', $input ) : [];
        },
        'default' => [],
    ] );
    register_setting( 'dp_toolbox_df_settings', 'dp_toolbox_hidden_admin_bar', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            return is_array( $input ) ? array_map( 'sanitize_text_field', $input ) : [];
        },
        'default' => [],
    ] );
} );

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Disable Features',
        'Disable Features',
        'manage_options',
        'dp-toolbox-disable-features',
        'dp_toolbox_df_admin_page'
    );
} );

function dp_toolbox_df_admin_page() {
    $features = dp_toolbox_df_get_features();
    $disabled = dp_toolbox_df_get_disabled();
    dp_toolbox_page_start( 'Disable Features', 'Schakel WordPress-functies uit die je niet nodig hebt.' );
    ?>
    <style>
        .dp-df-category { margin-bottom: 24px; }
        .dp-df-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 18px; margin-bottom: 8px;
            display: flex; align-items: center; gap: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-df-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-df-card.is-disabled { border-left: 4px solid #d63638; background: #fef7f7; }
        .dp-df-toggle input[type="checkbox"] { display: none; }
        .dp-df-toggle label {
            display: block; width: 42px; height: 22px; background: #d63638;
            border-radius: 11px; position: relative; cursor: pointer; transition: background 0.2s; flex-shrink: 0;
        }
        .dp-df-toggle label::after {
            content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;
            background: #fff; border-radius: 50%; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .dp-df-toggle input:checked + label { background: #00a32a; }
        .dp-df-toggle input:checked + label::after { transform: translateX(20px); }
        .dp-df-info { flex: 1; min-width: 0; }
        .dp-df-info h3 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-df-info p { margin: 0; color: #666; font-size: 12px; line-height: 1.4; }
        .dp-df-status { flex-shrink: 0; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 3px 8px; border-radius: 4px; }
        .dp-df-status.on { color: #00a32a; background: #edfaef; }
        .dp-df-status.off { color: #d63638; background: #fce9e9; }
        .dp-df-card .dp-ab-toggle label { background: #00a32a; }
        .dp-df-card .dp-ab-toggle label::after { transform: translateX(20px); }
        .dp-df-card .dp-ab-toggle input:checked + label { background: #d63638; }
        .dp-df-card .dp-ab-toggle input:checked + label::after { transform: translateX(0); }
        .dp-df-legend { display: flex; gap: 16px; margin-bottom: 16px; font-size: 12px; color: #666; }
        .dp-df-legend span { display: flex; align-items: center; gap: 5px; }
        .dp-df-legend .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
        .dp-df-legend .dot.green { background: #00a32a; }
        .dp-df-legend .dot.red { background: #d63638; }
    </style>

    <div class="dp-df-legend">
        <span><span class="dot green"></span> Feature is actief</span>
        <span><span class="dot red"></span> Feature is uitgeschakeld</span>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_df_settings' ); ?>

        <?php foreach ( $features as $cat_key => $category ) : ?>
            <div class="dp-df-category">
                <div class="dp-section-header">
                    <span class="dashicons <?php echo esc_attr( $category['icon'] ); ?>"></span>
                    <h2><?php echo esc_html( $category['label'] ); ?></h2>
                </div>
                <?php foreach ( $category['items'] as $key => $feature ) :
                    $is_off = in_array( $key, $disabled, true );
                ?>
                    <div class="dp-df-card <?php echo $is_off ? 'is-disabled' : ''; ?>">
                        <div class="dp-df-toggle">
                            <input type="checkbox" id="dp-df-<?php echo esc_attr( $key ); ?>" name="dp_toolbox_disabled_features[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $is_off ); ?>>
                            <label for="dp-df-<?php echo esc_attr( $key ); ?>"></label>
                        </div>
                        <div class="dp-df-info">
                            <h3><?php echo esc_html( $feature['label'] ); ?></h3>
                            <p><?php echo esc_html( $feature['desc'] ); ?></p>
                        </div>
                        <span class="dp-df-status <?php echo $is_off ? 'off' : 'on'; ?>"><?php echo $is_off ? 'Uit' : 'Aan'; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php
        $ab_plugins = dp_toolbox_df_get_admin_bar_plugins();
        $ab_hidden  = dp_toolbox_df_get_hidden_admin_bar();
        if ( ! empty( $ab_plugins ) ) : ?>
            <div class="dp-df-category">
                <div class="dp-section-header">
                    <span class="dashicons dashicons-admin-links"></span>
                    <h2>Plugin items in admin bar</h2>
                </div>
                <p style="color:#666;font-size:12px;margin:-4px 0 10px;">Automatisch gedetecteerd. Verberg met de toggle.</p>
                <?php foreach ( $ab_plugins as $node_id => $title ) :
                    if ( empty( $title ) ) continue;
                    $is_hidden = in_array( $node_id, $ab_hidden, true );
                ?>
                    <div class="dp-df-card <?php echo $is_hidden ? 'is-disabled' : ''; ?>">
                        <div class="dp-df-toggle dp-ab-toggle">
                            <input type="checkbox" id="dp-ab-<?php echo esc_attr( $node_id ); ?>" name="dp_toolbox_hidden_admin_bar[]" value="<?php echo esc_attr( $node_id ); ?>" <?php checked( $is_hidden ); ?>>
                            <label for="dp-ab-<?php echo esc_attr( $node_id ); ?>"></label>
                        </div>
                        <div class="dp-df-info">
                            <h3><?php echo esc_html( $title ); ?></h3>
                            <p><code style="font-size:11px;background:#f0f0f0;padding:2px 5px;border-radius:3px;"><?php echo esc_html( $node_id ); ?></code></p>
                        </div>
                        <span class="dp-df-status <?php echo $is_hidden ? 'off' : 'on'; ?>"><?php echo $is_hidden ? 'Verborgen' : 'Zichtbaar'; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php submit_button( 'Opslaan' ); ?>
    </form>
    <?php
    dp_toolbox_page_end();
}