<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/*  Register settings                                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_login_url', 'dp_toolbox_login_slug', [
        'type'              => 'string',
        'sanitize_callback' => function ( $input ) {
            $slug = sanitize_title( trim( $input ) );
            // Prevent reserved slugs
            $reserved = [ 'wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'wp-content', 'wp-includes' ];
            if ( in_array( $slug, $reserved, true ) ) {
                add_settings_error( 'dp_toolbox_login_slug', 'reserved', 'Deze slug is gereserveerd en kan niet gebruikt worden.' );
                return get_option( 'dp_toolbox_login_slug', '' );
            }
            return $slug;
        },
        'default' => '',
    ] );
} );

/* ------------------------------------------------------------------ */
/*  Admin menu                                                         */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Custom Login URL',
        'Custom Login URL',
        'manage_options',
        'dp-toolbox-login-url',
        'dp_toolbox_login_url_page'
    );
} );

/* ------------------------------------------------------------------ */
/*  Render page                                                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_login_url_page() {
    $slug = dp_toolbox_clu_get_slug();
    $is_active = ! empty( $slug );
    $emergency_key = substr( md5( AUTH_KEY ), 0, 8 );

    dp_toolbox_page_start( 'Custom Login URL', 'Verplaats de WordPress login-pagina naar een eigen URL.' );
    ?>
    <style>
        .dp-clu-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px;
            margin-bottom: 16px;
        }
        .dp-clu-card h3 {
            margin: 0 0 16px; font-size: 14px; font-weight: 700; color: #1d2327;
            padding-bottom: 10px; border-bottom: 2px solid #281E5D;
            display: flex; align-items: center; gap: 8px;
        }
        .dp-clu-card h3 .dashicons {
            color: #281E5D; font-size: 16px; width: 16px; height: 16px;
        }

        .dp-clu-status {
            display: flex; gap: 12px; margin-bottom: 20px;
        }
        .dp-clu-status-box {
            flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center;
        }
        .dp-clu-status-box .dashicons {
            font-size: 24px; width: 24px; height: 24px; margin-bottom: 4px;
        }
        .dp-clu-status-box.is-ok .dashicons { color: #16a34a; }
        .dp-clu-status-box.is-off .dashicons { color: #999; }
        .dp-clu-status-label { display: block; font-size: 11px; color: #666; margin-top: 4px; }
        .dp-clu-status-value { display: block; font-size: 13px; font-weight: 600; color: #1d2327; word-break: break-all; }

        .dp-clu-field {
            margin-bottom: 16px;
        }
        .dp-clu-field label {
            display: block; font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px;
        }
        .dp-clu-url-input {
            display: flex; align-items: center; gap: 0;
        }
        .dp-clu-url-prefix {
            background: #f0f0f1; border: 1px solid #ddd; border-right: none;
            padding: 8px 12px; font-size: 13px; color: #666;
            border-radius: 6px 0 0 6px; white-space: nowrap;
        }
        .dp-clu-url-input input[type="text"] {
            flex: 1; padding: 8px 12px; border: 1px solid #ddd;
            font-size: 13px; border-radius: 0 6px 6px 0;
            transition: border-color 0.15s;
        }
        .dp-clu-url-input input:focus {
            border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
        }
        .dp-clu-hint {
            font-size: 11px; color: #999; margin-top: 6px; line-height: 1.5;
        }

        .dp-clu-actions {
            display: flex; gap: 10px; align-items: center;
        }
        .dp-clu-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 8px 24px; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background 0.2s;
        }
        .dp-clu-btn:hover { background: #4a3a8a; }

        .dp-clu-emergency {
            background: #fef9ee; border: 1px solid #f0e0b8; border-radius: 8px;
            padding: 16px 20px; margin-top: 8px;
        }
        .dp-clu-emergency h4 {
            margin: 0 0 8px; font-size: 13px; font-weight: 700; color: #92400e;
            display: flex; align-items: center; gap: 6px;
        }
        .dp-clu-emergency h4 .dashicons { font-size: 16px; width: 16px; height: 16px; color: #c48a00; }
        .dp-clu-emergency p {
            margin: 0 0 6px; font-size: 12px; color: #78350f; line-height: 1.5;
        }
        .dp-clu-emergency code {
            background: #fff; border: 1px solid #e0d5b8; padding: 4px 10px;
            border-radius: 4px; font-size: 12px; word-break: break-all;
            display: inline-block; margin-top: 4px;
        }
    </style>

    <!-- Status -->
    <div class="dp-clu-status">
        <div class="dp-clu-status-box <?php echo $is_active ? 'is-ok' : 'is-off'; ?>">
            <span class="dashicons <?php echo $is_active ? 'dashicons-lock' : 'dashicons-unlock'; ?>"></span>
            <span class="dp-clu-status-label">Login URL</span>
            <span class="dp-clu-status-value"><?php echo $is_active ? home_url( '/' . $slug . '/' ) : 'Standaard (wp-login.php)'; ?></span>
        </div>
        <div class="dp-clu-status-box <?php echo $is_active ? 'is-ok' : 'is-off'; ?>">
            <span class="dashicons <?php echo $is_active ? 'dashicons-shield' : 'dashicons-minus'; ?>"></span>
            <span class="dp-clu-status-label">wp-login.php</span>
            <span class="dp-clu-status-value"><?php echo $is_active ? 'Geblokkeerd' : 'Toegankelijk'; ?></span>
        </div>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_login_url' ); ?>

        <div class="dp-clu-card">
            <h3><span class="dashicons dashicons-admin-network"></span> Login URL</h3>

            <div class="dp-clu-field">
                <label for="dp-clu-slug">Custom login slug</label>
                <div class="dp-clu-url-input">
                    <span class="dp-clu-url-prefix"><?php echo esc_html( home_url( '/' ) ); ?></span>
                    <input type="text" id="dp-clu-slug" name="dp_toolbox_login_slug"
                           value="<?php echo esc_attr( $slug ); ?>"
                           placeholder="mijn-login">
                </div>
                <p class="dp-clu-hint">
                    Kies een unieke, moeilijk te raden slug. Laat leeg om de standaard wp-login.php te gebruiken.<br>
                    Alleen kleine letters, cijfers en streepjes. Vermijd woorden als "login" of "admin".
                </p>
            </div>

            <div class="dp-clu-actions">
                <button type="submit" class="dp-clu-btn">Opslaan</button>
            </div>
        </div>
    </form>

    <!-- Emergency access info -->
    <div class="dp-clu-emergency">
        <h4><span class="dashicons dashicons-warning"></span> Noodtoegang</h4>
        <p>
            Buitengesloten? Gebruik deze URL om alsnog bij wp-login.php te komen.
            Bewaar deze URL op een veilige plek.
        </p>
        <code><?php echo esc_html( site_url( 'wp-login.php?dp_emergency=' . $emergency_key ) ); ?></code>
        <p style="margin-top:8px;">
            Je kunt ook de module uitschakelen door de DP Toolbox plugin te deactiveren via FTP of WP-CLI.
        </p>
    </div>

    <?php
    dp_toolbox_page_end();
}
