<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    register_setting( 'dp_toolbox_revisions', 'dp_toolbox_revision_limit', [
        'type' => 'integer',
        'sanitize_callback' => function ( $input ) {
            $val = absint( $input );
            return $val >= 0 ? $val : 5;
        },
        'default' => 5,
    ] );
} );

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Revision Limiter',
        'Revision Limiter',
        'manage_options',
        'dp-toolbox-revisions',
        'dp_toolbox_revisions_page'
    );
} );

function dp_toolbox_revisions_page() {
    $limit = (int) get_option( 'dp_toolbox_revision_limit', 5 );
    global $wpdb;
    $revision_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
    dp_toolbox_page_start( 'Revision Limiter', 'Beperk het aantal revisies om de database schoon te houden.' );
    ?>
        <style>

            .dp-rv-card {
                background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px;
            }
            .dp-rv-stat {
                display: flex; gap: 20px; margin-bottom: 24px;
            }
            .dp-rv-stat-box {
                flex: 1; background: #f8f7fc; border-radius: 8px; padding: 16px; text-align: center;
            }
            .dp-rv-stat-num {
                display: block; font-size: 28px; font-weight: 700; color: #281E5D; line-height: 1; margin-bottom: 4px;
            }
            .dp-rv-stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
            .dp-rv-setting {
                display: flex; align-items: center; gap: 12px; margin-bottom: 20px;
            }
            .dp-rv-setting label { font-size: 14px; font-weight: 500; color: #1d2327; }
            .dp-rv-setting input[type="number"] {
                width: 80px; padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px;
                font-size: 14px; text-align: center;
            }
            .dp-rv-setting input:focus {
                border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
            }
            .dp-rv-hint { font-size: 12px; color: #999; margin-bottom: 20px; }
            .dp-rv-btn {
                background: #281E5D; color: #fff; border: none; border-radius: 6px;
                padding: 8px 24px; font-size: 14px; font-weight: 600; cursor: pointer;
                transition: background 0.2s;
            }
            .dp-rv-btn:hover { background: #4a3a8a; }
            .dp-rv-actions { display: flex; gap: 10px; align-items: center; }
            .dp-rv-btn-danger {
                background: #fff; color: #d63638; border: 1px solid #d63638; border-radius: 6px;
                padding: 8px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
                transition: all 0.2s;
            }
            .dp-rv-btn-danger:hover { background: #d63638; color: #fff; }
        </style>



        <div class="dp-rv-card">
            <div class="dp-rv-stat">
                <div class="dp-rv-stat-box">
                    <span class="dp-rv-stat-num"><?php echo $limit; ?></span>
                    <span class="dp-rv-stat-label">Max revisies</span>
                </div>
                <div class="dp-rv-stat-box">
                    <span class="dp-rv-stat-num"><?php echo number_format( $revision_count ); ?></span>
                    <span class="dp-rv-stat-label">Revisies in database</span>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'dp_toolbox_revisions' ); ?>

                <div class="dp-rv-setting">
                    <label for="dp-rv-limit">Maximaal aantal revisies per bericht:</label>
                    <input type="number" id="dp-rv-limit" name="dp_toolbox_revision_limit"
                           value="<?php echo esc_attr( $limit ); ?>" min="0" max="100">
                </div>
                <p class="dp-rv-hint">0 = geen revisies opslaan. WordPress standaard is ongelimiteerd.</p>

                <div class="dp-rv-actions">
                    <button type="submit" class="dp-rv-btn">Opslaan</button>
                    <button type="button" id="dp-rv-cleanup" class="dp-rv-btn-danger">Oude revisies opruimen</button>
                </div>
            </form>
        </div>

    <script>
    document.getElementById('dp-rv-cleanup').addEventListener('click', function() {
        var limit = document.getElementById('dp-rv-limit').value;
        if (!confirm('Dit verwijdert alle revisies boven het limiet (' + limit + ') voor elk bericht. Doorgaan?')) return;
        this.textContent = 'Bezig...';
        this.disabled = true;
        var btn = this;

        fetch('<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=dp_toolbox_cleanup_revisions&nonce=<?php echo wp_create_nonce( "dp_toolbox_revisions" ); ?>&limit=' + limit
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                alert('Klaar! ' + res.data.deleted + ' revisie(s) verwijderd.');
                location.reload();
            } else {
                alert('Fout: ' + (res.data || 'Onbekend'));
                btn.textContent = 'Oude revisies opruimen';
                btn.disabled = false;
            }
        });
    });
    </script>
    <?php
    dp_toolbox_page_end();
}
