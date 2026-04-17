<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/*  Register settings                                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_smtp', 'dp_toolbox_smtp_settings', [
        'type'              => 'array',
        'sanitize_callback' => 'dp_toolbox_smtp_sanitize',
        'default'           => [],
    ] );
} );

function dp_toolbox_smtp_sanitize( $input ) {
    $old = get_option( 'dp_toolbox_smtp_settings', [] );

    $sanitized = [
        'host'       => sanitize_text_field( $input['host'] ?? '' ),
        'port'       => absint( $input['port'] ?? 587 ),
        'encryption' => in_array( $input['encryption'] ?? '', [ 'tls', 'ssl', 'none' ], true )
                            ? $input['encryption'] : 'tls',
        'auth'       => ! empty( $input['auth'] ),
        'username'   => sanitize_text_field( $input['username'] ?? '' ),
        'from_email' => sanitize_email( $input['from_email'] ?? '' ),
        'from_name'  => sanitize_text_field( $input['from_name'] ?? '' ),
    ];

    // Encrypt password — keep old if field is empty (placeholder shown)
    $password = $input['password'] ?? '';
    if ( ! empty( $password ) ) {
        $sanitized['password'] = dp_toolbox_smtp_encrypt( $password );
    } elseif ( isset( $old['password'] ) ) {
        $sanitized['password'] = $old['password'];
    } else {
        $sanitized['password'] = '';
    }

    return $sanitized;
}

/* ------------------------------------------------------------------ */
/*  Admin menu                                                         */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'SMTP Mailer',
        'SMTP Mailer',
        'manage_options',
        'dp-toolbox-smtp',
        'dp_toolbox_smtp_page'
    );
} );

/* ------------------------------------------------------------------ */
/*  Render page                                                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_smtp_page() {
    $smtp = dp_toolbox_smtp_get_settings();
    $has_password = ! empty( get_option( 'dp_toolbox_smtp_settings', [] )['password'] );

    dp_toolbox_page_start( 'SMTP Mailer', 'Configureer een SMTP-server voor betrouwbare e-mailverzending.' );
    ?>
    <style>
        .dp-smtp-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px;
            margin-bottom: 16px;
        }
        .dp-smtp-card h3 {
            margin: 0 0 16px; font-size: 14px; font-weight: 700; color: #1d2327;
            padding-bottom: 10px; border-bottom: 2px solid #281E5D;
            display: flex; align-items: center; gap: 8px;
        }
        .dp-smtp-card h3 .dashicons {
            color: #281E5D; font-size: 16px; width: 16px; height: 16px;
        }
        .dp-smtp-row {
            display: flex; gap: 16px; margin-bottom: 14px;
        }
        .dp-smtp-field {
            flex: 1; display: flex; flex-direction: column; gap: 4px;
        }
        .dp-smtp-field label {
            font-size: 12px; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .dp-smtp-field input[type="text"],
        .dp-smtp-field input[type="email"],
        .dp-smtp-field input[type="number"],
        .dp-smtp-field input[type="password"],
        .dp-smtp-field select {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; transition: border-color 0.15s;
        }
        .dp-smtp-field input:focus,
        .dp-smtp-field select:focus {
            border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
        }
        .dp-smtp-field .dp-smtp-hint {
            font-size: 11px; color: #999; margin-top: 2px;
        }

        .dp-smtp-toggle-row {
            display: flex; align-items: center; gap: 10px; margin-bottom: 14px;
        }
        .dp-smtp-toggle-row span { font-size: 13px; color: #1d2327; font-weight: 500; }

        .dp-smtp-actions {
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
        }
        .dp-smtp-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 8px 24px; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background 0.2s;
        }
        .dp-smtp-btn:hover { background: #4a3a8a; }
        .dp-smtp-btn-test {
            background: #fff; color: #281E5D; border: 1px solid #281E5D; border-radius: 6px;
            padding: 8px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: all 0.2s;
        }
        .dp-smtp-btn-test:hover { background: #281E5D; color: #fff; }

        .dp-smtp-test-result {
            margin-top: 12px; padding: 10px 14px; border-radius: 6px;
            font-size: 13px; display: none;
        }
        .dp-smtp-test-result.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .dp-smtp-test-result.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .dp-smtp-status {
            display: flex; gap: 12px; margin-bottom: 20px;
        }
        .dp-smtp-status-box {
            flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center;
        }
        .dp-smtp-status-box .dashicons {
            font-size: 24px; width: 24px; height: 24px; margin-bottom: 4px;
        }
        .dp-smtp-status-box.is-ok .dashicons { color: #16a34a; }
        .dp-smtp-status-box.is-off .dashicons { color: #999; }
        .dp-smtp-status-label { display: block; font-size: 11px; color: #666; margin-top: 4px; }
        .dp-smtp-status-value { display: block; font-size: 13px; font-weight: 600; color: #1d2327; }
    </style>

    <!-- Status overview -->
    <div class="dp-smtp-status">
        <div class="dp-smtp-status-box <?php echo ! empty( $smtp['host'] ) ? 'is-ok' : 'is-off'; ?>">
            <span class="dashicons <?php echo ! empty( $smtp['host'] ) ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
            <span class="dp-smtp-status-label">SMTP Server</span>
            <span class="dp-smtp-status-value"><?php echo ! empty( $smtp['host'] ) ? esc_html( $smtp['host'] ) : 'Niet ingesteld'; ?></span>
        </div>
        <div class="dp-smtp-status-box <?php echo ! empty( $smtp['from_email'] ) ? 'is-ok' : 'is-off'; ?>">
            <span class="dashicons <?php echo ! empty( $smtp['from_email'] ) ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
            <span class="dp-smtp-status-label">Afzender</span>
            <span class="dp-smtp-status-value"><?php echo ! empty( $smtp['from_email'] ) ? esc_html( $smtp['from_email'] ) : 'WordPress standaard'; ?></span>
        </div>
        <div class="dp-smtp-status-box <?php echo $smtp['encryption'] !== 'none' ? 'is-ok' : 'is-off'; ?>">
            <span class="dashicons <?php echo $smtp['encryption'] !== 'none' ? 'dashicons-lock' : 'dashicons-unlock'; ?>"></span>
            <span class="dp-smtp-status-label">Encryptie</span>
            <span class="dp-smtp-status-value"><?php echo strtoupper( $smtp['encryption'] ); ?></span>
        </div>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_smtp' ); ?>

        <!-- Server settings -->
        <div class="dp-smtp-card">
            <h3><span class="dashicons dashicons-cloud"></span> Server</h3>

            <div class="dp-smtp-row">
                <div class="dp-smtp-field" style="flex:3">
                    <label for="dp-smtp-host">SMTP Host</label>
                    <input type="text" id="dp-smtp-host" name="dp_toolbox_smtp_settings[host]"
                           value="<?php echo esc_attr( $smtp['host'] ); ?>"
                           placeholder="smtp.example.com">
                </div>
                <div class="dp-smtp-field" style="flex:1">
                    <label for="dp-smtp-port">Poort</label>
                    <input type="number" id="dp-smtp-port" name="dp_toolbox_smtp_settings[port]"
                           value="<?php echo esc_attr( $smtp['port'] ); ?>"
                           min="1" max="65535">
                </div>
                <div class="dp-smtp-field" style="flex:1">
                    <label for="dp-smtp-encryption">Encryptie</label>
                    <select id="dp-smtp-encryption" name="dp_toolbox_smtp_settings[encryption]">
                        <option value="tls" <?php selected( $smtp['encryption'], 'tls' ); ?>>TLS</option>
                        <option value="ssl" <?php selected( $smtp['encryption'], 'ssl' ); ?>>SSL</option>
                        <option value="none" <?php selected( $smtp['encryption'], 'none' ); ?>>Geen</option>
                    </select>
                </div>
            </div>

            <div class="dp-smtp-toggle-row">
                <div class="dp-toggle">
                    <input type="checkbox" id="dp-smtp-auth" name="dp_toolbox_smtp_settings[auth]" value="1"
                           <?php checked( $smtp['auth'] ); ?>>
                    <label for="dp-smtp-auth"></label>
                </div>
                <span>Authenticatie vereist</span>
            </div>

            <div class="dp-smtp-row" id="dp-smtp-auth-fields">
                <div class="dp-smtp-field">
                    <label for="dp-smtp-username">Gebruikersnaam</label>
                    <input type="text" id="dp-smtp-username" name="dp_toolbox_smtp_settings[username]"
                           value="<?php echo esc_attr( $smtp['username'] ); ?>"
                           placeholder="user@example.com" autocomplete="off">
                </div>
                <div class="dp-smtp-field">
                    <label for="dp-smtp-password">Wachtwoord</label>
                    <input type="password" id="dp-smtp-password" name="dp_toolbox_smtp_settings[password]"
                           value="" placeholder="<?php echo $has_password ? '••••••••' : ''; ?>" autocomplete="new-password">
                    <span class="dp-smtp-hint"><?php echo $has_password ? 'Laat leeg om het huidige wachtwoord te behouden.' : 'Wordt versleuteld opgeslagen.'; ?></span>
                </div>
            </div>
        </div>

        <!-- From settings -->
        <div class="dp-smtp-card">
            <h3><span class="dashicons dashicons-email"></span> Afzender</h3>

            <div class="dp-smtp-row">
                <div class="dp-smtp-field">
                    <label for="dp-smtp-from-email">Afzender e-mail</label>
                    <input type="email" id="dp-smtp-from-email" name="dp_toolbox_smtp_settings[from_email]"
                           value="<?php echo esc_attr( $smtp['from_email'] ); ?>"
                           placeholder="info@example.com">
                    <span class="dp-smtp-hint">Overschrijft de standaard WordPress afzender.</span>
                </div>
                <div class="dp-smtp-field">
                    <label for="dp-smtp-from-name">Afzender naam</label>
                    <input type="text" id="dp-smtp-from-name" name="dp_toolbox_smtp_settings[from_name]"
                           value="<?php echo esc_attr( $smtp['from_name'] ); ?>"
                           placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="dp-smtp-actions">
            <button type="submit" class="dp-smtp-btn">Opslaan</button>
            <button type="button" id="dp-smtp-test-btn" class="dp-smtp-btn-test">
                <span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                Testmail versturen
            </button>
        </div>

        <div id="dp-smtp-test-result" class="dp-smtp-test-result"></div>
    </form>

    <script>
    (function() {
        // Toggle auth fields visibility
        var authCheckbox = document.getElementById('dp-smtp-auth');
        var authFields   = document.getElementById('dp-smtp-auth-fields');

        function toggleAuth() {
            authFields.style.display = authCheckbox.checked ? '' : 'none';
        }
        authCheckbox.addEventListener('change', toggleAuth);
        toggleAuth();

        // Test email
        document.getElementById('dp-smtp-test-btn').addEventListener('click', function() {
            var btn    = this;
            var result = document.getElementById('dp-smtp-test-result');
            var to     = prompt('Naar welk e-mailadres wil je een testmail sturen?', '<?php echo esc_js( wp_get_current_user()->user_email ); ?>');

            if (!to) return;

            btn.disabled = true;
            btn.textContent = 'Verzenden...';
            result.style.display = 'none';

            fetch('<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=dp_toolbox_smtp_test&nonce=<?php echo wp_create_nonce( "dp_toolbox_smtp" ); ?>&to=' + encodeURIComponent(to)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                result.style.display = 'block';
                if (res.success) {
                    result.className = 'dp-smtp-test-result success';
                    result.textContent = res.data.message;
                } else {
                    result.className = 'dp-smtp-test-result error';
                    result.textContent = res.data || 'Onbekende fout.';
                }
                btn.disabled = false;
                btn.innerHTML = '<span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span> Testmail versturen';
            })
            .catch(function() {
                result.style.display = 'block';
                result.className = 'dp-smtp-test-result error';
                result.textContent = 'Netwerkfout.';
                btn.disabled = false;
                btn.innerHTML = '<span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span> Testmail versturen';
            });
        });
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
