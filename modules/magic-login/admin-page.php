<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_ml_settings', 'dp_toolbox_magic_login', [
        'type'              => 'array',
        'sanitize_callback' => 'dp_toolbox_ml_sanitize_settings',
        'default'           => dp_toolbox_ml_defaults(),
    ] );
} );

function dp_toolbox_ml_sanitize_settings( $input ) {
    $defaults = dp_toolbox_ml_defaults();

    if ( ! is_array( $input ) ) {
        return $defaults;
    }

    $selectable = array_keys( dp_toolbox_ml_selectable_roles() );
    $roles      = isset( $input['roles'] ) ? (array) $input['roles'] : [];
    $roles      = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $selectable ) );

    $redirect = isset( $input['redirect'] ) ? trim( (string) $input['redirect'] ) : '';
    $redirect = $redirect ? esc_url_raw( $redirect ) : '';

    return [
        'roles'         => $roles,
        'ttl'           => max( 5, min( 120, (int) ( $input['ttl'] ?? $defaults['ttl'] ) ) ),
        'confirm_step'  => empty( $input['confirm_step'] ) ? 0 : 1,
        'redirect'      => $redirect,
        'show_on_login' => empty( $input['show_on_login'] ) ? 0 : 1,
        'max_per_hour'  => max( 1, min( 20, (int) ( $input['max_per_hour'] ?? $defaults['max_per_hour'] ) ) ),
        'method'        => in_array( $input['method'] ?? '', [ 'both', 'link', 'code' ], true ) ? $input['method'] : $defaults['method'],
        'code_ttl'      => max( 3, min( 60, (int) ( $input['code_ttl'] ?? $defaults['code_ttl'] ) ) ),
        'code_attempts' => max( 3, min( 10, (int) ( $input['code_attempts'] ?? $defaults['code_attempts'] ) ) ),
        'mail_subject'  => sanitize_text_field( $input['mail_subject'] ?? $defaults['mail_subject'] ),
        'mail_body'     => sanitize_textarea_field( $input['mail_body'] ?? $defaults['mail_body'] ),
    ];
}

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'magic-login', 'dp_toolbox_ml_render_inline', [
            'title'       => 'Magic Login',
            'description' => 'Inloggen via een eenmalige link per e-mail, zonder wachtwoord.',
        ] );
    }
} );

/**
 * Aantal op dit moment openstaande inloglinks.
 */
function dp_toolbox_ml_count_pending() {
    global $wpdb;

    $rows = $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_dp_magic_login'"
    );

    $open = 0;
    foreach ( (array) $rows as $raw ) {
        $data = maybe_unserialize( $raw );
        if ( is_array( $data ) && ! empty( $data['expires'] ) && time() <= (int) $data['expires'] ) {
            $open++;
        }
    }

    return $open;
}

function dp_toolbox_ml_render_inline() {
    $s          = dp_toolbox_ml_get_settings();
    $selectable = dp_toolbox_ml_selectable_roles();
    $pending    = dp_toolbox_ml_count_pending();
    ?>
    <style>
        .dp-ml-admin .dp-ml-row {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 16px 18px; margin-bottom: 8px;
        }
        .dp-ml-admin .dp-ml-row h3 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-ml-admin .dp-ml-row p.desc { margin: 0 0 10px; color: #666; font-size: 12px; line-height: 1.5; }
        .dp-ml-admin .dp-ml-inline { display: flex; align-items: center; gap: 12px; }
        .dp-ml-admin .dp-ml-inline .dp-ml-grow { flex: 1; min-width: 0; }
        .dp-ml-admin label.dp-ml-check { display: inline-flex; align-items: center; gap: 6px; margin-right: 16px; font-size: 13px; }
        .dp-ml-admin input[type="text"],
        .dp-ml-admin input[type="url"],
        .dp-ml-admin input[type="number"],
        .dp-ml-admin select,
        .dp-ml-admin textarea { border-radius: 6px; border: 1px solid #c3c4c7; }
        .dp-ml-admin input[type="text"],
        .dp-ml-admin input[type="url"],
        .dp-ml-admin textarea { width: 100%; }
        .dp-ml-admin textarea { min-height: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; line-height: 1.6; }
        .dp-ml-note {
            background: #f8f7fc; border-left: 3px solid #281E5D; border-radius: 0 6px 6px 0;
            padding: 12px 14px; margin-bottom: 12px; font-size: 12px; line-height: 1.6; color: #3c434a;
        }
        .dp-ml-note strong { color: #281E5D; }
        .dp-ml-note code { background: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
        .dp-ml-tokens code { background: #f0ecff; color: #281E5D; padding: 2px 6px; border-radius: 4px; font-size: 11px; margin-right: 4px; }
        .dp-ml-pending { font-size: 12px; color: #666; margin: 0 0 12px; }
    </style>

    <div class="dp-ml-admin">

        <div class="dp-ml-note">
            <strong>Beheerders zijn uitgesloten.</strong> Accounts die de site kunnen beheren
            (<code>manage_options</code>) kunnen nooit via een inloglink binnenkomen — die blijven op
            wachtwoord. Het risico van een inloglink zit in overname van de mailbox, en dat risico hoort
            niet op een beheerdersaccount.
        </div>

        <?php if ( $pending ) : ?>
            <p class="dp-ml-pending"><?php echo (int) $pending; ?> inloglink(s) op dit moment geldig.</p>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'dp_toolbox_ml_settings' ); ?>

            <div class="dp-ml-row">
                <h3>Wie mag een inloglink gebruiken?</h3>
                <p class="desc">Alleen rollen die je hier aanvinkt. Staat er niets aan, dan doet de module niets.</p>
                <?php foreach ( $selectable as $slug => $label ) : ?>
                    <label class="dp-ml-check">
                        <input type="checkbox" name="dp_toolbox_magic_login[roles][]"
                               value="<?php echo esc_attr( $slug ); ?>"
                               <?php checked( in_array( $slug, (array) $s['roles'], true ) ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Wat sturen we mee?</h3>
                        <p class="desc" style="margin-bottom:0;">
                            Een link is het snelst, maar werkt slecht als de mail op een ander
                            apparaat binnenkomt dan waar iemand verder wil. Een code van zes cijfers
                            tik je gewoon over. Allebei sturen dekt beide situaties.
                        </p>
                    </div>
                    <select name="dp_toolbox_magic_login[method]">
                        <?php foreach ( [ 'both' => 'Link én code', 'link' => 'Alleen een link', 'code' => 'Alleen een code' ] as $waarde => $label ) : ?>
                            <option value="<?php echo esc_attr( $waarde ); ?>" <?php selected( $s['method'], $waarde ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Hoe lang is een code geldig?</h3>
                        <p class="desc" style="margin-bottom:0;">
                            Korter dan de link: zes cijfers zijn nu eenmaal te raden, dus die moeten
                            snel verlopen. De code werkt bovendien alléén in het venster waar hij is
                            aangevraagd, en gaat na <?php echo esc_html( $s['code_attempts'] ); ?> foute
                            pogingen definitief op slot.
                        </p>
                    </div>
                    <select name="dp_toolbox_magic_login[code_ttl]">
                        <?php foreach ( [ 5, 10, 15, 30 ] as $min ) : ?>
                            <option value="<?php echo esc_attr( $min ); ?>" <?php selected( (int) $s['code_ttl'], $min ); ?>>
                                <?php echo esc_html( $min ); ?> minuten
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Pogingen per code</h3>
                        <p class="desc" style="margin-bottom:0;">Daarna is de code dood en moet er een nieuwe aangevraagd worden.</p>
                    </div>
                    <input type="number" min="3" max="10" step="1" style="width:80px;"
                           name="dp_toolbox_magic_login[code_attempts]"
                           value="<?php echo esc_attr( $s['code_attempts'] ); ?>">
                </div>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Hoe lang is een link geldig?</h3>
                        <p class="desc" style="margin-bottom:0;">Korter is veiliger. Vijftien minuten is ruim genoeg om een mail te openen.</p>
                    </div>
                    <select name="dp_toolbox_magic_login[ttl]">
                        <?php foreach ( [ 5, 15, 30, 60, 120 ] as $min ) : ?>
                            <option value="<?php echo esc_attr( $min ); ?>" <?php selected( (int) $s['ttl'], $min ); ?>>
                                <?php echo esc_html( $min ); ?> minuten
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Bevestigknop tonen</h3>
                        <p class="desc" style="margin-bottom:0;">
                            De link opent een pagina met één knop in plaats van meteen in te loggen.
                            Houd dit aan: mailscanners van Outlook en bedrijfsnetwerken openen links
                            automatisch, en zouden de link anders opmaken vóór de ontvanger klikt.
                        </p>
                    </div>
                    <div class="dp-toggle">
                        <input type="checkbox" id="dp-ml-confirm" name="dp_toolbox_magic_login[confirm_step]"
                               value="1" <?php checked( ! empty( $s['confirm_step'] ) ); ?>>
                        <label for="dp-ml-confirm"></label>
                    </div>
                </div>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Formulier tonen op de inlogpagina</h3>
                        <p class="desc" style="margin-bottom:0;">Zet het aanvraagblok onder het normale inlogformulier van WordPress.</p>
                    </div>
                    <div class="dp-toggle">
                        <input type="checkbox" id="dp-ml-showlogin" name="dp_toolbox_magic_login[show_on_login]"
                               value="1" <?php checked( ! empty( $s['show_on_login'] ) ); ?>>
                        <label for="dp-ml-showlogin"></label>
                    </div>
                </div>
            </div>

            <div class="dp-ml-row">
                <div class="dp-ml-inline">
                    <div class="dp-ml-grow">
                        <h3>Maximum aanvragen per account per uur</h3>
                        <p class="desc" style="margin-bottom:0;">Voorkomt dat iemand een lid volspamt met inlogmails.</p>
                    </div>
                    <input type="number" min="1" max="20" style="width:80px;"
                           name="dp_toolbox_magic_login[max_per_hour]"
                           value="<?php echo esc_attr( $s['max_per_hour'] ); ?>">
                </div>
            </div>

            <div class="dp-ml-row">
                <h3>Waar komt iemand terecht na het inloggen?</h3>
                <p class="desc">Laat leeg voor de homepage. Kwam iemand van een afgeschermde pagina, dan gaat hij daar sowieso naartoe terug.</p>
                <input type="url" name="dp_toolbox_magic_login[redirect]"
                       value="<?php echo esc_attr( $s['redirect'] ); ?>"
                       placeholder="<?php echo esc_attr( home_url( '/voor-leden/' ) ); ?>">
            </div>

            <div class="dp-ml-row">
                <h3>De e-mail</h3>
                <p class="desc dp-ml-tokens">
                    Beschikbaar: <code>{naam}</code><code>{link}</code><code>{code}</code><code>{site}</code><code>{geldigheid}</code><code>{codeduur}</code>
                    <br>Een regel met <code>{link}</code> of <code>{code}</code> verdwijnt vanzelf uit de mail
                    wanneer die manier niet aanstaat, zodat er geen kale regel achterblijft.
                </p>
                <input type="text" name="dp_toolbox_magic_login[mail_subject]"
                       value="<?php echo esc_attr( $s['mail_subject'] ); ?>"
                       style="margin-bottom:8px;" placeholder="Onderwerp">
                <textarea name="dp_toolbox_magic_login[mail_body]"><?php echo esc_textarea( $s['mail_body'] ); ?></textarea>
            </div>

            <div class="dp-ml-note">
                Zet het formulier op een eigen pagina met <code>[dp_magic_login]</code>.
                Wil je iemand daarna naar een vaste pagina sturen, gebruik dan
                <code>[dp_magic_login redirect="<?php echo esc_attr( home_url( '/voor-leden/' ) ); ?>"]</code>.
            </div>

            <?php submit_button( 'Opslaan' ); ?>
        </form>
    </div>
    <?php
}
