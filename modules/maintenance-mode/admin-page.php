<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_maintenance', 'dp_toolbox_maintenance_enabled', [
        'type' => 'boolean',
        'sanitize_callback' => function ( $input ) { return (bool) $input; },
        'default' => false,
    ] );
} );

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Maintenance Mode',
        'Maintenance Mode',
        'manage_options',
        'dp-toolbox-maintenance',
        'dp_toolbox_maintenance_page'
    );
} );

function dp_toolbox_maintenance_page() {
    $enabled = get_option( 'dp_toolbox_maintenance_enabled', false );
    dp_toolbox_page_start( 'Maintenance Mode', 'Ingelogde gebruikers met bewerkrechten kunnen de site gewoon bekijken.' );
    ?>
        <style>

            .dp-mt-card {
                background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
                padding: 24px; text-align: center;
            }
            .dp-mt-card.is-on { border: 2px solid #d63638; background: #fef7f7; }
            .dp-mt-card.is-off { border: 2px solid #00a32a; background: #f9fef9; }
            .dp-mt-status { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
            .dp-mt-status.on { color: #d63638; }
            .dp-mt-status.off { color: #00a32a; }
            .dp-mt-desc { color: #666; font-size: 13px; margin-bottom: 20px; }
            .dp-mt-toggle { display: inline-block; }
            .dp-mt-toggle input { display: none; }
            .dp-mt-toggle label {
                display: block; width: 60px; height: 32px; border-radius: 16px;
                position: relative; cursor: pointer; transition: background 0.2s;
            }
            .dp-mt-toggle label::after {
                content: ''; position: absolute; top: 4px; left: 4px; width: 24px; height: 24px;
                background: #fff; border-radius: 50%; transition: transform 0.2s;
                box-shadow: 0 1px 4px rgba(0,0,0,0.2);
            }
            .dp-mt-toggle input:checked + label { background: #d63638; }
            .dp-mt-toggle input:not(:checked) + label { background: #00a32a; }
            .dp-mt-toggle input:checked + label::after { transform: translateX(28px); }
            .dp-mt-btn {
                margin-top: 20px; background: #281E5D; color: #fff; border: none;
                border-radius: 6px; padding: 8px 24px; font-size: 14px; font-weight: 600;
                cursor: pointer; transition: background 0.2s;
            }
            .dp-mt-btn:hover { background: #4a3a8a; }
        </style>



        <form method="post" action="options.php">
            <?php settings_fields( 'dp_toolbox_maintenance' ); ?>

            <div class="dp-mt-card <?php echo $enabled ? 'is-on' : 'is-off'; ?>">
                <div class="dp-mt-status <?php echo $enabled ? 'on' : 'off'; ?>">
                    <?php echo $enabled ? '&#128295; Onderhoudsmodus is AAN' : '&#9989; Site is online'; ?>
                </div>
                <div class="dp-mt-desc">
                    <?php echo $enabled
                        ? 'Bezoekers zien een onderhoudspagina. Jij kunt gewoon werken.'
                        : 'De site is zichtbaar voor iedereen.'; ?>
                </div>
                <div class="dp-mt-toggle">
                    <input type="hidden" name="dp_toolbox_maintenance_enabled" value="0">
                    <input type="checkbox" id="dp-mt-switch" name="dp_toolbox_maintenance_enabled" value="1" <?php checked( $enabled ); ?>>
                    <label for="dp-mt-switch"></label>
                </div>
                <br>
                <button type="submit" class="dp-mt-btn">Opslaan</button>
            </div>
        </form>
    <?php
    dp_toolbox_page_end();
}
