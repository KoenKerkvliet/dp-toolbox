<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Thumbnails Manager',
        'Thumbnails Manager',
        'manage_options',
        'dp-toolbox-thumbnails',
        'dp_toolbox_thumbnails_page'
    );
} );

function dp_toolbox_thumbnails_page() {
    $sizes    = dp_toolbox_tm_get_all_sizes();
    $nonce    = wp_create_nonce( 'dp_toolbox_thumbnails' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    global $wpdb;
    $total_images = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
    );

    dp_toolbox_page_start( 'Thumbnails Manager', 'Bekijk geregistreerde thumbnail-formaten en regenereer thumbnails.' );
    ?>
    <style>
        .dp-tm-stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .dp-tm-stat {
            flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center;
        }
        .dp-tm-stat-num { display: block; font-size: 24px; font-weight: 700; color: #281E5D; }
        .dp-tm-stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.3px; }

        .dp-tm-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px;
            margin-bottom: 16px;
        }
        .dp-tm-card h3 {
            margin: 0 0 16px; font-size: 14px; font-weight: 700; color: #1d2327;
            padding-bottom: 10px; border-bottom: 2px solid #281E5D;
            display: flex; align-items: center; gap: 8px;
        }
        .dp-tm-card h3 .dashicons { color: #281E5D; font-size: 16px; width: 16px; height: 16px; }

        /* Sizes table */
        .dp-tm-table { width: 100%; border-collapse: collapse; }
        .dp-tm-table th {
            text-align: left; font-size: 11px; font-weight: 600; color: #888;
            text-transform: uppercase; letter-spacing: 0.3px;
            padding: 8px 12px; border-bottom: 2px solid #281E5D;
        }
        .dp-tm-table td {
            padding: 8px 12px; border-bottom: 1px solid #eee;
            font-size: 13px; color: #1d2327;
        }
        .dp-tm-table tr:hover td { background: #faf9ff; }
        .dp-tm-name { font-family: monospace; font-weight: 600; color: #281E5D; }
        .dp-tm-dims { font-family: monospace; color: #666; }
        .dp-tm-badge {
            display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 8px;
            border-radius: 4px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .dp-tm-badge.crop-yes { background: #eee8ff; color: #281E5D; }
        .dp-tm-badge.crop-no { background: #f0f0f1; color: #888; }
        .dp-tm-source { font-size: 11px; color: #999; }

        /* Regenerate section */
        .dp-tm-regen {
            display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
        }
        .dp-tm-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 10px 24px; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background 0.15s; display: inline-flex; align-items: center; gap: 6px;
        }
        .dp-tm-btn:hover { background: #4a3a8a; }
        .dp-tm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .dp-tm-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }

        /* Progress bar */
        .dp-tm-progress { display: none; margin-top: 16px; }
        .dp-tm-progress.is-visible { display: block; }
        .dp-tm-progress-bar-wrap {
            background: #e0e0e0; border-radius: 6px; height: 12px; overflow: hidden;
            margin-bottom: 8px;
        }
        .dp-tm-progress-bar {
            background: linear-gradient(90deg, #281E5D, #4a3a8a);
            height: 100%; border-radius: 6px; transition: width 0.3s;
            width: 0%;
        }
        .dp-tm-progress-text { font-size: 12px; color: #666; }
        .dp-tm-progress-done {
            display: none; padding: 12px 16px; border-radius: 8px;
            background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0;
            font-size: 13px; font-weight: 600; margin-top: 12px;
        }
        .dp-tm-progress-done.is-visible { display: block; }
    </style>

    <!-- Stats -->
    <div class="dp-tm-stats">
        <div class="dp-tm-stat">
            <span class="dp-tm-stat-num"><?php echo $total_images; ?></span>
            <span class="dp-tm-stat-label">Afbeeldingen</span>
        </div>
        <div class="dp-tm-stat">
            <span class="dp-tm-stat-num"><?php echo count( $sizes ) - 1; ?></span>
            <span class="dp-tm-stat-label">Thumbnail-formaten</span>
        </div>
        <div class="dp-tm-stat">
            <span class="dp-tm-stat-num" id="dp-tm-disk">—</span>
            <span class="dp-tm-stat-label">Schijfruimte</span>
        </div>
    </div>

    <!-- Registered sizes -->
    <div class="dp-tm-card">
        <h3><span class="dashicons dashicons-format-image"></span> Geregistreerde formaten</h3>

        <table class="dp-tm-table">
            <thead>
                <tr>
                    <th>Naam</th>
                    <th>Afmetingen</th>
                    <th>Bijsnijden</th>
                    <th>Bron</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sizes as $name => $size ) :
                    if ( $name === 'full' ) continue;
                    $w = $size['width'] ?: '∞';
                    $h = $size['height'] ?: '∞';
                ?>
                    <tr>
                        <td class="dp-tm-name"><?php echo esc_html( $name ); ?></td>
                        <td class="dp-tm-dims"><?php echo esc_html( $w . ' × ' . $h ); ?> px</td>
                        <td>
                            <span class="dp-tm-badge <?php echo $size['crop'] ? 'crop-yes' : 'crop-no'; ?>">
                                <?php echo $size['crop'] ? 'Ja' : 'Nee'; ?>
                            </span>
                        </td>
                        <td class="dp-tm-source"><?php echo esc_html( $size['source'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Regenerate -->
    <div class="dp-tm-card">
        <h3><span class="dashicons dashicons-update"></span> Thumbnails regenereren</h3>

        <p style="margin:0 0 16px;font-size:13px;color:#666;line-height:1.5;">
            Genereer alle thumbnails opnieuw op basis van de huidige geregistreerde formaten.
            Handig na het wijzigen van thumbnail-afmetingen, na een thema-wissel,
            of als thumbnails ontbreken.
        </p>

        <div class="dp-tm-regen">
            <button type="button" class="dp-tm-btn" id="dp-tm-regen-btn">
                <span class="dashicons dashicons-update"></span>
                Alle thumbnails regenereren
            </button>
            <span style="font-size:12px;color:#999;"><?php echo $total_images; ?> afbeelding(en)</span>
        </div>

        <div class="dp-tm-progress" id="dp-tm-progress">
            <div class="dp-tm-progress-bar-wrap">
                <div class="dp-tm-progress-bar" id="dp-tm-bar"></div>
            </div>
            <span class="dp-tm-progress-text" id="dp-tm-progress-text">0 / <?php echo $total_images; ?></span>
        </div>

        <div class="dp-tm-progress-done" id="dp-tm-done"></div>
    </div>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';
        var total   = <?php echo $total_images; ?>;
        var batchSize = 3;

        // Load disk stats
        var fd = new FormData();
        fd.append('action', 'dp_toolbox_tm_stats');
        fd.append('nonce', nonce);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    document.getElementById('dp-tm-disk').textContent = res.data.disk_usage;
                }
            });

        // Regenerate
        document.getElementById('dp-tm-regen-btn').addEventListener('click', function() {
            if (!confirm('Alle thumbnails voor ' + total + ' afbeelding(en) opnieuw genereren?\n\nDit kan even duren bij veel afbeeldingen.')) return;

            var btn      = this;
            var progress = document.getElementById('dp-tm-progress');
            var bar      = document.getElementById('dp-tm-bar');
            var text     = document.getElementById('dp-tm-progress-text');
            var done     = document.getElementById('dp-tm-done');

            btn.disabled = true;
            progress.classList.add('is-visible');
            done.classList.remove('is-visible');

            var totalProcessed = 0;
            var totalErrors    = 0;

            function processBatch(offset) {
                var fd = new FormData();
                fd.append('action', 'dp_toolbox_tm_regenerate');
                fd.append('nonce', nonce);
                fd.append('offset', offset);
                fd.append('batch', batchSize);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) {
                            done.textContent = 'Fout: ' + (res.data || 'Onbekend');
                            done.style.background = '#fef2f2';
                            done.style.color = '#991b1b';
                            done.style.borderColor = '#fecaca';
                            done.classList.add('is-visible');
                            btn.disabled = false;
                            return;
                        }

                        totalProcessed += res.data.processed;
                        totalErrors    += res.data.errors.length;

                        var pct = Math.round( (res.data.offset / res.data.total) * 100 );
                        bar.style.width = Math.min(pct, 100) + '%';
                        text.textContent = res.data.offset + ' / ' + res.data.total;

                        if (res.data.done) {
                            bar.style.width = '100%';
                            text.textContent = res.data.total + ' / ' + res.data.total;
                            done.textContent = 'Klaar! ' + totalProcessed + ' afbeelding(en) verwerkt.' +
                                (totalErrors > 0 ? ' (' + totalErrors + ' fout(en))' : '');
                            done.classList.add('is-visible');
                            btn.disabled = false;
                        } else {
                            processBatch(res.data.offset);
                        }
                    })
                    .catch(function() {
                        done.textContent = 'Netwerkfout tijdens regenereren.';
                        done.style.background = '#fef2f2';
                        done.style.color = '#991b1b';
                        done.style.borderColor = '#fecaca';
                        done.classList.add('is-visible');
                        btn.disabled = false;
                    });
            }

            processBatch(0);
        });
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
