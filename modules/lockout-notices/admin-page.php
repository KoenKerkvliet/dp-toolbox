<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_ln_settings', DP_TOOLBOX_LN_OPTIE, [
        'type'              => 'array',
        'sanitize_callback' => 'dp_toolbox_ln_sanitize',
        'default'           => dp_toolbox_ln_defaults(),
    ] );
} );

function dp_toolbox_ln_sanitize( $input ) {
    $input = is_array( $input ) ? $input : [];

    return [
        'filteren'    => empty( $input['filteren'] ) ? 0 : 1,
        'log_naar_al' => empty( $input['log_naar_al'] ) ? 0 : 1,
    ];
}

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'lockout-notices', 'dp_toolbox_ln_render_inline', [
            'title'       => 'Uitsluitingsmeldingen',
            'description' => 'Alleen mail bij een bestaand account, niet bij bots.',
        ] );
    }
} );

function dp_toolbox_ln_render_inline() {
    $s        = dp_toolbox_ln_instellingen();
    $stats    = dp_toolbox_ln_stats();
    $mailt    = dp_toolbox_ln_aios_mailt();
    $wachtrij = dp_toolbox_ln_wachtrij();
    $al_aan   = function_exists( 'dp_toolbox_is_module_enabled' ) && dp_toolbox_is_module_enabled( 'activity-log' );
    ?>
    <style>
        .dp-ln-stats { display: flex; gap: 12px; margin-bottom: 16px; }
        .dp-ln-stat { flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center; }
        .dp-ln-stat b { display: block; font-size: 24px; color: #281E5D; line-height: 1; margin-bottom: 4px; }
        .dp-ln-stat span { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
        .dp-ln-rij {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 16px 18px; margin-bottom: 8px; display: flex; align-items: center; gap: 14px;
        }
        .dp-ln-rij .dp-ln-tekst { flex: 1; min-width: 0; }
        .dp-ln-rij h3 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-ln-rij p { margin: 0; color: #666; font-size: 12px; line-height: 1.5; }
        .dp-ln-note { border-radius: 0 6px 6px 0; padding: 11px 13px; margin-bottom: 14px;
            font-size: 12px; line-height: 1.6; }
        .dp-ln-note.info { background: #f8f7fc; border-left: 3px solid #281E5D; color: #3c434a; }
        .dp-ln-note.waarschuwing { background: #fcf9e8; border-left: 3px solid #dba617; color: #8a6d1a; }
        .dp-ln-note code { background: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
    </style>

    <?php if ( ! $mailt ) : ?>
        <div class="dp-ln-note waarschuwing">
            AIOS stuurt op dit moment <strong>helemaal geen</strong> uitsluitingsmails
            (<code>aiowps_enable_email_notify</code> staat uit). Deze module heeft dan niets te filteren.
            Zet die melding in AIOS aan als je bericht wilt krijgen wanneer een lid vastloopt.
        </div>
    <?php else : ?>
        <div class="dp-ln-note info">
            AIOS mailt uitsluitingen naar <strong><?php echo esc_html( dp_toolbox_ln_aios_ontvangers() ); ?></strong>.
            Met deze module aan gaat daar alleen nog bericht heen als de gebruikersnaam
            of het e-mailadres bij een bestaand account hoort.
        </div>
    <?php endif; ?>

    <div class="dp-ln-stats">
        <div class="dp-ln-stat">
            <b><?php echo (int) $stats['deze_maand']; ?></b><span>Deze maand stil gehouden</span>
        </div>
        <div class="dp-ln-stat">
            <b><?php echo (int) $stats['totaal']; ?></b><span>Sinds het aanzetten</span>
        </div>
        <div class="dp-ln-stat">
            <b><?php echo (int) $wachtrij['echte']; ?></b><span>Op een echt account</span>
        </div>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_ln_settings' ); ?>

        <div class="dp-ln-rij">
            <div class="dp-toggle">
                <input type="checkbox" id="dp-ln-filteren" name="<?php echo esc_attr( DP_TOOLBOX_LN_OPTIE ); ?>[filteren]"
                       value="1" <?php checked( ! empty( $s['filteren'] ) ); ?>>
                <label for="dp-ln-filteren"></label>
            </div>
            <div class="dp-ln-tekst">
                <h3>Alleen melden bij een bestaand account</h3>
                <p>
                    Bots proberen namen als <code>admin</code> die hier niet bestaan. Die uitsluitingen
                    blijven gewoon staan in het overzicht van AIOS &mdash; je krijgt er alleen geen mail meer over.
                </p>
            </div>
        </div>

        <div class="dp-ln-rij">
            <div class="dp-toggle">
                <input type="checkbox" id="dp-ln-log" name="<?php echo esc_attr( DP_TOOLBOX_LN_OPTIE ); ?>[log_naar_al]"
                       value="1" <?php checked( ! empty( $s['log_naar_al'] ) ); ?>>
                <label for="dp-ln-log"></label>
            </div>
            <div class="dp-ln-tekst">
                <h3>Onderdrukte meldingen in de Activity Log</h3>
                <p>
                    Voor een langer spoor dan AIOS zelf bewaart &mdash; dat ruimt zijn tabel periodiek op.
                    Kost wel regels in je log.
                    <?php if ( ! $al_aan ) : ?>
                        <br><em>De module Activity Log staat uit; dit doet dan niets.</em>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php submit_button( 'Opslaan' ); ?>
    </form>
    <?php
}
