<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_checklist_settings', DP_TOOLBOX_CHECKLIST_GROEPEN, [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            $geldig = array_keys( dp_toolbox_get_checklist_groups() );
            $input  = is_array( $input ) ? array_map( 'sanitize_key', $input ) : [];

            return array_values( array_intersect( $input, $geldig ) );
        },
    ] );
} );

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'checklist', 'dp_toolbox_checklist_render_inline', [
            'title'       => 'Oplevercheck',
            'description' => 'Kies welke onderdelen op het tabblad verschijnen.',
        ] );
    }
} );

function dp_toolbox_checklist_render_inline() {
    $groepen = dp_toolbox_get_checklist_groups();
    $aan     = dp_toolbox_checklist_groep_keuze();
    $state   = dp_toolbox_checklist_get_state();
    ?>
    <style>
        .dp-clg-kaart {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 18px; margin-bottom: 8px;
            display: flex; align-items: center; gap: 14px;
            transition: border-color .2s, box-shadow .2s;
        }
        .dp-clg-kaart:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,.08); }
        .dp-clg-kaart.is-uit { opacity: .55; }
        .dp-clg-kaart.is-uit:hover { opacity: 1; }
        .dp-clg-info { flex: 1; min-width: 0; }
        .dp-clg-info h3 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #1d2327;
            display: flex; align-items: center; gap: 7px; }
        .dp-clg-info h3 .dashicons { font-size: 15px; width: 15px; height: 15px; color: #281E5D; }
        .dp-clg-info p { margin: 0; color: #666; font-size: 12px; }
        .dp-clg-telling { flex-shrink: 0; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 4px;
            background: #f0ecff; color: #281E5D; white-space: nowrap; }
        .dp-clg-telling.klaar { background: #edfaef; color: #00a32a; }
        .dp-clg-note { background: #f8f7fc; border-left: 3px solid #281E5D; border-radius: 0 6px 6px 0;
            padding: 12px 14px; margin-bottom: 14px; font-size: 12px; line-height: 1.6; color: #3c434a; }
    </style>

    <div class="dp-clg-note">
        Zet je alle onderdelen uit, dan blijft het tabblad leeg. Wil je de lijst helemaal kwijt,
        zet dan deze module uit &mdash; dan verdwijnt het tabblad zelf ook.
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_checklist_settings' ); ?>

        <?php
        /*
         * Zonder dit lege veld stuurt de browser niets mee als je álle vakjes
         * uitzet, en dan slaat options.php de optie helemaal niet op — het
         * laatste vinkje zou je dus nooit uit krijgen. De sanitize-callback
         * filtert deze lege waarde er weer uit.
         */
        ?>
        <input type="hidden" name="<?php echo esc_attr( DP_TOOLBOX_CHECKLIST_GROEPEN ); ?>[]" value="">

        <?php foreach ( $groepen as $key => $groep ) :
            $is_aan = in_array( $key, $aan, true );

            $totaal = count( $groep['items'] );
            $klaar  = 0;
            foreach ( $groep['items'] as $item ) {
                if ( dp_toolbox_checklist_item_done( $item, $state ) ) {
                    $klaar++;
                }
            }
        ?>
            <div class="dp-clg-kaart <?php echo $is_aan ? '' : 'is-uit'; ?>">
                <div class="dp-toggle">
                    <input type="checkbox"
                           id="dp-clg-<?php echo esc_attr( $key ); ?>"
                           name="<?php echo esc_attr( DP_TOOLBOX_CHECKLIST_GROEPEN ); ?>[]"
                           value="<?php echo esc_attr( $key ); ?>"
                           <?php checked( $is_aan ); ?>>
                    <label for="dp-clg-<?php echo esc_attr( $key ); ?>"></label>
                </div>
                <div class="dp-clg-info">
                    <h3>
                        <span class="dashicons <?php echo esc_attr( $groep['icon'] ?? 'dashicons-yes-alt' ); ?>"></span>
                        <?php echo esc_html( $groep['label'] ); ?>
                    </h3>
                    <p><?php echo (int) $totaal; ?> punten</p>
                </div>
                <span class="dp-clg-telling <?php echo $klaar === $totaal ? 'klaar' : ''; ?>">
                    <?php echo (int) $klaar; ?>/<?php echo (int) $totaal; ?>
                </span>
            </div>
        <?php endforeach; ?>

        <?php submit_button( 'Opslaan' ); ?>
    </form>
    <?php
}
