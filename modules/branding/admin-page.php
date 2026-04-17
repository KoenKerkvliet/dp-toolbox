<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/*  Register settings                                                  */
/* ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_branding', 'dp_toolbox_branding_color', [
        'type'              => 'string',
        'sanitize_callback' => function ( $input ) {
            $input = trim( $input );
            if ( $input === '' ) return '';
            // Add # if missing
            if ( $input[0] !== '#' ) $input = '#' . $input;
            // Only accept #RRGGBB
            if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $input ) ) {
                return strtolower( $input );
            }
            return get_option( 'dp_toolbox_branding_color', '' );
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
        'Branding',
        'Branding',
        'manage_options',
        'dp-toolbox-branding',
        'dp_toolbox_branding_page'
    );
} );

/* ------------------------------------------------------------------ */
/*  Render page                                                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_branding_page() {
    $color = get_option( 'dp_toolbox_branding_color', '' );

    // Predefined palette (inspiration)
    $presets = [
        '#281E5D' => 'DP Paars',
        '#2271b1' => 'WP Blauw',
        '#16a34a' => 'Groen',
        '#dc2626' => 'Rood',
        '#f59e0b' => 'Oranje',
        '#db2777' => 'Roze',
        '#0891b2' => 'Cyaan',
        '#7c3aed' => 'Violet',
        '#1d2327' => 'Zwart',
    ];

    dp_toolbox_page_start( 'Branding', 'Geef de WordPress admin-sidebar iconen een eigen merkkleur.' );
    ?>
    <style>
        .dp-br-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px;
            margin-bottom: 16px;
        }
        .dp-br-card h3 {
            margin: 0 0 16px; font-size: 14px; font-weight: 700; color: #1d2327;
            padding-bottom: 10px; border-bottom: 2px solid #281E5D;
            display: flex; align-items: center; gap: 8px;
        }
        .dp-br-card h3 .dashicons { color: #281E5D; font-size: 16px; width: 16px; height: 16px; }

        .dp-br-row { display: flex; gap: 16px; align-items: flex-end; }
        .dp-br-field { flex: 1; max-width: 280px; }
        .dp-br-field label {
            display: block; font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 6px;
        }

        .dp-br-input-group {
            display: flex; align-items: center; gap: 10px;
        }
        .dp-br-color-swatch {
            width: 44px; height: 44px; border-radius: 8px; border: 1px solid #ddd;
            flex-shrink: 0; cursor: pointer; position: relative; overflow: hidden;
        }
        .dp-br-color-swatch input[type="color"] {
            position: absolute; inset: -2px; border: none; padding: 0;
            width: calc(100% + 4px); height: calc(100% + 4px); cursor: pointer;
        }
        .dp-br-hex-input {
            padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px;
            font-size: 14px; font-family: monospace; font-weight: 600;
            text-transform: uppercase; width: 140px;
            transition: border-color 0.15s;
        }
        .dp-br-hex-input:focus {
            border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
        }

        .dp-br-presets { margin-top: 16px; }
        .dp-br-presets-label {
            font-size: 11px; color: #888; text-transform: uppercase;
            letter-spacing: 0.3px; margin-bottom: 8px;
        }
        .dp-br-preset-list {
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .dp-br-preset {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 10px; border: 1px solid #ddd; border-radius: 6px;
            background: #fff; cursor: pointer; font-size: 12px; color: #555;
            transition: all 0.15s;
        }
        .dp-br-preset:hover { border-color: #281E5D; color: #281E5D; }
        .dp-br-preset-swatch {
            width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,0.1);
        }

        .dp-br-preview {
            background: #1d2327; border-radius: 8px; padding: 16px 0;
            margin-top: 14px; max-width: 180px;
        }
        .dp-br-preview-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 16px; color: #c3c4c7; font-size: 13px;
        }
        .dp-br-preview-item .dashicons {
            color: var(--dp-br-preview, #a7aaad);
            font-size: 18px; width: 18px; height: 18px;
        }

        .dp-br-actions {
            display: flex; gap: 10px; align-items: center; margin-top: 20px;
        }
        .dp-br-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 10px 24px; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background 0.15s;
        }
        .dp-br-btn:hover { background: #4a3a8a; }
        .dp-br-btn-clear {
            background: #fff; color: #d63638; border: 1px solid #d63638; border-radius: 6px;
            padding: 10px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: all 0.15s;
        }
        .dp-br-btn-clear:hover { background: #d63638; color: #fff; }

        .dp-br-hint {
            font-size: 12px; color: #888; margin-top: 8px; line-height: 1.5;
        }
    </style>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_branding' ); ?>

        <div class="dp-br-card">
            <h3><span class="dashicons dashicons-admin-customizer"></span> Sidebar icon-kleur</h3>

            <div class="dp-br-row">
                <div class="dp-br-field">
                    <label for="dp-br-hex">Kleur</label>
                    <div class="dp-br-input-group">
                        <div class="dp-br-color-swatch" style="background:<?php echo esc_attr( $color ?: '#ffffff' ); ?>">
                            <input type="color" id="dp-br-picker" value="<?php echo esc_attr( $color ?: '#281E5D' ); ?>">
                        </div>
                        <input type="text" id="dp-br-hex" class="dp-br-hex-input"
                               name="dp_toolbox_branding_color"
                               value="<?php echo esc_attr( $color ); ?>"
                               placeholder="#281E5D" maxlength="7"
                               pattern="^#?[0-9a-fA-F]{6}$">
                    </div>
                    <p class="dp-br-hint">Laat leeg om de standaard WordPress-kleur te gebruiken.</p>
                </div>

                <div>
                    <label style="font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:6px;">Voorbeeld</label>
                    <div class="dp-br-preview" id="dp-br-preview" style="--dp-br-preview: <?php echo esc_attr( $color ?: '#a7aaad' ); ?>;">
                        <div class="dp-br-preview-item"><span class="dashicons dashicons-dashboard"></span> Dashboard</div>
                        <div class="dp-br-preview-item"><span class="dashicons dashicons-admin-post"></span> Berichten</div>
                        <div class="dp-br-preview-item"><span class="dashicons dashicons-admin-media"></span> Media</div>
                        <div class="dp-br-preview-item"><span class="dashicons dashicons-admin-page"></span> Pagina's</div>
                    </div>
                </div>
            </div>

            <div class="dp-br-presets">
                <div class="dp-br-presets-label">Snelkeuze</div>
                <div class="dp-br-preset-list">
                    <?php foreach ( $presets as $hex => $name ) : ?>
                        <button type="button" class="dp-br-preset" data-color="<?php echo esc_attr( $hex ); ?>">
                            <span class="dp-br-preset-swatch" style="background:<?php echo esc_attr( $hex ); ?>"></span>
                            <?php echo esc_html( $name ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dp-br-actions">
                <button type="submit" class="dp-br-btn">Opslaan</button>
                <?php if ( $color ) : ?>
                    <button type="button" class="dp-br-btn-clear" id="dp-br-clear">Kleur wissen</button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <script>
    (function() {
        var hexInput = document.getElementById('dp-br-hex');
        var picker   = document.getElementById('dp-br-picker');
        var swatch   = picker.parentElement;
        var preview  = document.getElementById('dp-br-preview');

        function updateColor(color, updateInputs) {
            if (!color) color = '';
            var valid = /^#[0-9a-fA-F]{6}$/.test(color);
            if (updateInputs) {
                hexInput.value = color;
            }
            swatch.style.background = valid ? color : '#ffffff';
            if (valid) {
                picker.value = color;
                preview.style.setProperty('--dp-br-preview', color);
            } else {
                preview.style.setProperty('--dp-br-preview', '#a7aaad');
            }
        }

        // Hex input → update picker + preview
        hexInput.addEventListener('input', function() {
            var val = this.value.trim();
            if (val && val[0] !== '#') val = '#' + val;
            updateColor(val, false);
        });

        // Picker → update hex input + preview
        picker.addEventListener('input', function() {
            updateColor(this.value, true);
        });

        // Presets
        document.querySelectorAll('.dp-br-preset').forEach(function(btn) {
            btn.addEventListener('click', function() {
                updateColor(this.dataset.color, true);
            });
        });

        // Clear
        var clearBtn = document.getElementById('dp-br-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                hexInput.value = '';
                updateColor('', false);
                hexInput.closest('form').submit();
            });
        }
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
