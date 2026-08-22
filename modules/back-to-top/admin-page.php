<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_btt_settings', DP_TOOLBOX_BTT_OPTION, [
        'type'              => 'array',
        'sanitize_callback' => 'dp_toolbox_btt_sanitize',
        'default'           => dp_toolbox_btt_defaults(),
    ] );
} );

function dp_toolbox_btt_sanitize( $in ) {
    $d   = dp_toolbox_btt_defaults();
    $out = dp_toolbox_btt_settings();

    if ( ! is_array( $in ) ) {
        return $out;
    }

    $ranges = [
        'size'          => [ 32, 96 ],
        'offset_side'   => [ 0, 200 ],
        'offset_bottom' => [ 0, 200 ],
        'gap'           => [ 0, 60 ],
        'radius'        => [ 0, 50 ],
        'threshold'     => [ 50, 5000 ],
    ];
    foreach ( $ranges as $key => $range ) {
        if ( isset( $in[ $key ] ) ) {
            $out[ $key ] = max( $range[0], min( $range[1], absint( $in[ $key ] ) ) );
        }
    }

    if ( isset( $in['position'] ) ) {
        $out['position'] = ( 'left' === $in['position'] ) ? 'left' : 'right';
    }

    // Leeg mag: dan valt de achtergrond terug op var(--primary).
    if ( isset( $in['bg'] ) ) {
        $bg         = sanitize_hex_color( $in['bg'] );
        $out['bg']  = $bg ? $bg : '';
    }
    if ( isset( $in['icon_color'] ) ) {
        $ic               = sanitize_hex_color( $in['icon_color'] );
        $out['icon_color'] = $ic ? $ic : $d['icon_color'];
    }

    foreach ( [ 'shadow', 'hide_mobile' ] as $k ) {
        $out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
    }

    return $out;
}

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'back-to-top', 'dp_toolbox_btt_render_inline', [
            'title'       => 'Terug naar boven',
            'description' => 'Vormgeving en plaatsing van de zweefknop.',
        ] );
    }
} );

function dp_toolbox_btt_render_inline() {
    $s     = dp_toolbox_btt_settings();
    $chat  = dp_toolbox_btt_detect_chat();
    $place = dp_toolbox_btt_placement();
    $opt   = DP_TOOLBOX_BTT_OPTION;
    ?>
    <style>
        .dp-btt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .dp-btt-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #1d2327; }
        .dp-btt-field input[type=number], .dp-btt-field select { width: 100%; }
        .dp-btt-field p { margin: 4px 0 0; font-size: 11px; color: #666; }
        .dp-btt-note { border-left: 4px solid #281E5D; background: #f6f5ff; padding: 12px 14px; margin: 0 0 16px; }
        .dp-btt-note.is-solo { border-left-color: #ccc; background: #f5f5f5; }
        .dp-btt-note strong { display: block; margin-bottom: 3px; }
        .dp-btt-note code { background: #fff; padding: 1px 5px; border-radius: 3px; }
    </style>

    <?php if ( $chat ) : ?>
        <div class="dp-btt-note">
            <strong>DP Chat gedetecteerd</strong>
            De chatknop staat <?php echo esc_html( $chat['position'] === 'left' ? 'linksonder' : 'rechtsonder' ); ?>
            op <?php echo (int) $chat['bottom']; ?>&nbsp;px van de onderkant.
            Deze knop wordt daarom automatisch op <code><?php echo (int) $place['bottom']; ?>&nbsp;px</code> gezet,
            met dezelfde zijafstand van <code><?php echo (int) $place['side']; ?>&nbsp;px</code>.
            Je eigen afstand-instellingen worden zolang genegeerd, zodat ze netjes op één lijn staan.
        </div>
    <?php else : ?>
        <div class="dp-btt-note is-solo">
            <strong>DP Chat niet actief</strong>
            De knop gebruikt je eigen afstanden hieronder. Zet je DP Chat later aan, dan schuift deze knop er vanzelf boven.
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_btt_settings' ); ?>

        <div class="dp-btt-grid">
            <div class="dp-btt-field">
                <label for="btt-size">Grootte</label>
                <input type="number" id="btt-size" name="<?php echo esc_attr( $opt ); ?>[size]" min="32" max="96" value="<?php echo (int) $s['size']; ?>" />
                <p>In pixels. 60 komt overeen met de DP Chat-knop.</p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-radius">Ronding</label>
                <input type="number" id="btt-radius" name="<?php echo esc_attr( $opt ); ?>[radius]" min="0" max="50" value="<?php echo (int) $s['radius']; ?>" />
                <p>50 is een cirkel, 0 een vierkant.</p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-position">Kant</label>
                <select id="btt-position" name="<?php echo esc_attr( $opt ); ?>[position]">
                    <option value="right" <?php selected( $s['position'], 'right' ); ?>>Rechts</option>
                    <option value="left" <?php selected( $s['position'], 'left' ); ?>>Links</option>
                </select>
                <p>Staat DP Chat aan de andere kant, dan stapelen ze niet.</p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-gap">Tussenruimte</label>
                <input type="number" id="btt-gap" name="<?php echo esc_attr( $opt ); ?>[gap]" min="0" max="60" value="<?php echo (int) $s['gap']; ?>" />
                <p>Ruimte tussen deze knop en de chatknop.</p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-side">Afstand zijkant</label>
                <input type="number" id="btt-side" name="<?php echo esc_attr( $opt ); ?>[offset_side]" min="0" max="200" value="<?php echo (int) $s['offset_side']; ?>" />
                <p<?php echo $chat ? ' style="color:#a06000"' : ''; ?>><?php echo $chat ? 'Nu overschreven door DP Chat.' : 'In pixels.'; ?></p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-bottom">Afstand onderkant</label>
                <input type="number" id="btt-bottom" name="<?php echo esc_attr( $opt ); ?>[offset_bottom]" min="0" max="200" value="<?php echo (int) $s['offset_bottom']; ?>" />
                <p<?php echo $chat ? ' style="color:#a06000"' : ''; ?>><?php echo $chat ? 'Nu overschreven door DP Chat.' : 'In pixels.'; ?></p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-bg">Achtergrondkleur</label>
                <input type="text" id="btt-bg" name="<?php echo esc_attr( $opt ); ?>[bg]" class="regular-text" placeholder="#9e86ff" value="<?php echo esc_attr( $s['bg'] ); ?>" />
                <p>Leeg laten gebruikt <code>var(--primary)</code> uit je thema.</p>
            </div>

            <div class="dp-btt-field">
                <label for="btt-icon">Pijlkleur</label>
                <input type="text" id="btt-icon" name="<?php echo esc_attr( $opt ); ?>[icon_color]" class="regular-text" value="<?php echo esc_attr( $s['icon_color'] ); ?>" />
            </div>

            <div class="dp-btt-field">
                <label for="btt-threshold">Verschijnt na</label>
                <input type="number" id="btt-threshold" name="<?php echo esc_attr( $opt ); ?>[threshold]" min="50" max="5000" value="<?php echo (int) $s['threshold']; ?>" />
                <p>Aantal pixels scrollen voordat de knop opduikt.</p>
            </div>
        </div>

        <p>
            <label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[shadow]" value="1" <?php checked( $s['shadow'], 1 ); ?> /> Slagschaduw tonen</label><br />
            <label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[hide_mobile]" value="1" <?php checked( $s['hide_mobile'], 1 ); ?> /> Verbergen op smalle schermen (tot 520&nbsp;px)</label>
        </p>

        <?php submit_button( 'Opslaan' ); ?>
    </form>
    <?php
}
