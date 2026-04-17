<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_site_navigator', 'dp_toolbox_site_nav_show_admin_bar_in_editor', [
        'type'              => 'boolean',
        'sanitize_callback' => function ( $input ) { return (bool) $input; },
        'default'           => false,
    ] );

    register_setting( 'dp_toolbox_site_navigator', 'dp_toolbox_site_nav_show_bricks_settings', [
        'type'              => 'boolean',
        'sanitize_callback' => function ( $input ) { return (bool) $input; },
        'default'           => true,
    ] );

    register_setting( 'dp_toolbox_site_navigator', 'dp_toolbox_site_nav_show_plugin_settings', [
        'type'              => 'boolean',
        'sanitize_callback' => function ( $input ) { return (bool) $input; },
        'default'           => true,
    ] );
} );

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Site Navigator',
        'Site Navigator',
        'manage_options',
        'dp-toolbox-site-navigator',
        'dp_toolbox_site_nav_page'
    );
} );

function dp_toolbox_site_nav_page() {
    $show_admin_bar = get_option( 'dp_toolbox_site_nav_show_admin_bar_in_editor', false );
    $show_bricks    = get_option( 'dp_toolbox_site_nav_show_bricks_settings', true );
    $show_plugins   = get_option( 'dp_toolbox_site_nav_show_plugin_settings', true );
    $bricks_active  = dp_toolbox_site_nav_is_bricks_active();

    dp_toolbox_page_start( 'Site Navigator', 'Snelnavigatie via de admin bar voor Bricks Builder.' );
    ?>
    <style>
        .dp-sn-cards { display: flex; flex-direction: column; gap: 16px; margin-top: 16px; }
        .dp-sn-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 20px 24px; display: flex; align-items: center;
            justify-content: space-between; transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-sn-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-sn-card-info { flex: 1; }
        .dp-sn-card-title { font-size: 15px; font-weight: 600; color: #1d2327; margin-bottom: 4px; }
        .dp-sn-card-desc { font-size: 13px; color: #666; }
        .dp-sn-toggle input[type="checkbox"] { display: none; }
        .dp-sn-toggle label {
            display: block; width: 42px; height: 22px; background: #ccc;
            border-radius: 11px; position: relative; cursor: pointer;
            transition: background 0.2s; flex-shrink: 0;
        }
        .dp-sn-toggle label::after {
            content: ''; position: absolute; top: 3px; left: 3px;
            width: 16px; height: 16px; background: #fff; border-radius: 50%;
            transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .dp-sn-toggle input:checked + label { background: #281E5D; }
        .dp-sn-toggle input:checked + label::after { transform: translateX(20px); }
        .dp-sn-warning {
            background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px;
            padding: 14px 18px; color: #856404; font-size: 13px; margin-bottom: 16px;
        }
        .dp-sn-btn {
            margin-top: 20px; background: #281E5D; color: #fff; border: none;
            border-radius: 6px; padding: 8px 24px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background 0.2s;
        }
        .dp-sn-btn:hover { background: #4a3a8a; }
    </style>

    <?php if ( ! $bricks_active ) : ?>
        <div class="dp-sn-warning">
            Bricks Builder is niet actief op deze site. De Site Navigator verschijnt pas in de admin bar wanneer Bricks als thema is geactiveerd.
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_site_navigator' ); ?>

        <div class="dp-sn-cards">
            <div class="dp-sn-card">
                <div class="dp-sn-card-info">
                    <div class="dp-sn-card-title">Admin bar in Bricks editor</div>
                    <div class="dp-sn-card-desc">Toon de WordPress admin bar bovenin de Bricks editor, zodat je snel kunt navigeren terwijl je bouwt.</div>
                </div>
                <div class="dp-sn-toggle">
                    <input type="hidden" name="dp_toolbox_site_nav_show_admin_bar_in_editor" value="0">
                    <input type="checkbox" id="dp-sn-adminbar" name="dp_toolbox_site_nav_show_admin_bar_in_editor" value="1" <?php checked( $show_admin_bar ); ?>>
                    <label for="dp-sn-adminbar"></label>
                </div>
            </div>

            <div class="dp-sn-card">
                <div class="dp-sn-card-info">
                    <div class="dp-sn-card-title">Bricks instellingen</div>
                    <div class="dp-sn-card-desc">Toon directe links naar alle Bricks instellingspagina's in het navigatiemenu.</div>
                </div>
                <div class="dp-sn-toggle">
                    <input type="hidden" name="dp_toolbox_site_nav_show_bricks_settings" value="0">
                    <input type="checkbox" id="dp-sn-bricks" name="dp_toolbox_site_nav_show_bricks_settings" value="1" <?php checked( $show_bricks ); ?>>
                    <label for="dp-sn-bricks"></label>
                </div>
            </div>

            <div class="dp-sn-card">
                <div class="dp-sn-card-info">
                    <div class="dp-sn-card-title">Plugin instellingen</div>
                    <div class="dp-sn-card-desc">Toon links naar instellingspagina's van Bricks-gerelateerde plugins (ACSS, BricksExtras, etc.).</div>
                </div>
                <div class="dp-sn-toggle">
                    <input type="hidden" name="dp_toolbox_site_nav_show_plugin_settings" value="0">
                    <input type="checkbox" id="dp-sn-plugins" name="dp_toolbox_site_nav_show_plugin_settings" value="1" <?php checked( $show_plugins ); ?>>
                    <label for="dp-sn-plugins"></label>
                </div>
            </div>
        </div>

        <button type="submit" class="dp-sn-btn">Opslaan</button>
    </form>
    <?php
    dp_toolbox_page_end();
}
