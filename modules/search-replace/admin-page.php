<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Search & Replace',
        'Search & Replace',
        'manage_options',
        'dp-toolbox-search-replace',
        'dp_toolbox_search_replace_page'
    );
} );

function dp_toolbox_search_replace_page() {
    $tables   = dp_toolbox_sr_get_tables();
    $nonce    = wp_create_nonce( 'dp_toolbox_search_replace' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    global $wpdb;
    $core_tables = [
        $wpdb->posts,
        $wpdb->postmeta,
        $wpdb->options,
        $wpdb->comments,
        $wpdb->commentmeta,
        $wpdb->terms,
        $wpdb->termmeta,
        $wpdb->term_taxonomy,
        $wpdb->usermeta,
        $wpdb->links,
    ];

    dp_toolbox_page_start( 'Search & Replace', 'Zoek en vervang tekst in de WordPress database — serialization-aware.' );
    ?>
    <style>
        .dp-sr-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px;
            margin-bottom: 16px;
        }
        .dp-sr-card h3 {
            margin: 0 0 16px; font-size: 14px; font-weight: 700; color: #1d2327;
            padding-bottom: 10px; border-bottom: 2px solid #281E5D;
            display: flex; align-items: center; gap: 8px;
        }
        .dp-sr-card h3 .dashicons { color: #281E5D; font-size: 16px; width: 16px; height: 16px; }

        .dp-sr-field { margin-bottom: 16px; }
        .dp-sr-field label {
            display: block; font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px;
        }
        .dp-sr-field input[type="text"] {
            width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 14px; font-family: monospace; box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .dp-sr-field input:focus {
            border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
        }
        .dp-sr-hint { font-size: 11px; color: #999; margin-top: 4px; }

        /* Table selector */
        .dp-sr-tables-header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 10px;
        }
        .dp-sr-tables-header a {
            font-size: 12px; color: #281E5D; cursor: pointer; text-decoration: none;
        }
        .dp-sr-tables-header a:hover { text-decoration: underline; }

        .dp-sr-table-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 4px; max-height: 240px; overflow-y: auto;
            border: 1px solid #eee; border-radius: 6px; padding: 10px;
        }
        .dp-sr-table-item {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #555; font-family: monospace;
        }
        .dp-sr-table-item input { margin: 0; }
        .dp-sr-table-item.is-core { font-weight: 600; color: #1d2327; }

        /* Actions */
        .dp-sr-actions { display: flex; gap: 10px; align-items: center; margin-top: 20px; }
        .dp-sr-btn {
            border: none; border-radius: 6px; padding: 10px 24px;
            font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .dp-sr-btn-primary { background: #281E5D; color: #fff; }
        .dp-sr-btn-primary:hover { background: #4a3a8a; }
        .dp-sr-btn-secondary {
            background: #fff; color: #281E5D; border: 1px solid #281E5D;
        }
        .dp-sr-btn-secondary:hover { background: #f3f0ff; }
        .dp-sr-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }

        /* Results */
        .dp-sr-results {
            margin-top: 20px; display: none;
        }
        .dp-sr-results.is-visible { display: block; }

        .dp-sr-result-header {
            padding: 12px 16px; border-radius: 8px; font-size: 14px; font-weight: 600;
            margin-bottom: 12px;
        }
        .dp-sr-result-header.is-dry {
            background: #fef9ee; color: #92400e; border: 1px solid #f0e0b8;
        }
        .dp-sr-result-header.is-live {
            background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0;
        }
        .dp-sr-result-header.is-empty {
            background: #f0f0f1; color: #666; border: 1px solid #ddd;
        }

        .dp-sr-result-table { width: 100%; border-collapse: collapse; }
        .dp-sr-result-table th {
            text-align: left; font-size: 11px; font-weight: 600; color: #888;
            text-transform: uppercase; letter-spacing: 0.3px;
            padding: 8px 12px; border-bottom: 2px solid #281E5D;
        }
        .dp-sr-result-table td {
            padding: 8px 12px; border-bottom: 1px solid #eee;
            font-size: 13px; font-family: monospace;
        }
        .dp-sr-result-table .dp-sr-count {
            font-weight: 700; color: #281E5D;
        }

        /* Warning */
        .dp-sr-warning {
            background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;
            padding: 12px 16px; margin-bottom: 16px;
            font-size: 12px; color: #991b1b; line-height: 1.5;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .dp-sr-warning .dashicons { color: #dc2626; flex-shrink: 0; margin-top: 1px; }

        /* Progress */
        .dp-sr-progress {
            display: none; align-items: center; gap: 10px; margin-top: 12px;
        }
        .dp-sr-progress.is-visible { display: flex; }
        .dp-sr-spinner {
            width: 18px; height: 18px; border: 2px solid #ddd;
            border-top-color: #281E5D; border-radius: 50%;
            animation: dp-sr-spin 0.6s linear infinite;
        }
        @keyframes dp-sr-spin { to { transform: rotate(360deg); } }
        .dp-sr-progress-text { font-size: 13px; color: #666; }
    </style>

    <!-- Warning -->
    <div class="dp-sr-warning">
        <span class="dashicons dashicons-warning"></span>
        <div>
            <strong>Let op:</strong> Search & Replace wijzigt data direct in de database.
            Maak altijd eerst een backup. Gebruik de <em>Dry Run</em> knop om te controleren
            wat er gewijzigd wordt zonder daadwerkelijk iets aan te passen.
        </div>
    </div>

    <!-- Search & Replace form -->
    <div class="dp-sr-card">
        <h3><span class="dashicons dashicons-search"></span> Zoeken en vervangen</h3>

        <div class="dp-sr-field">
            <label for="dp-sr-search">Zoeken naar</label>
            <input type="text" id="dp-sr-search" placeholder="https://oud-domein.nl">
            <p class="dp-sr-hint">Exacte tekst die je wilt zoeken (hoofdlettergevoelig).</p>
        </div>

        <div class="dp-sr-field">
            <label for="dp-sr-replace">Vervangen door</label>
            <input type="text" id="dp-sr-replace" placeholder="https://nieuw-domein.nl">
            <p class="dp-sr-hint">Laat leeg om de zoekterm te verwijderen (niet aanbevolen voor URLs).</p>
        </div>
    </div>

    <!-- Table selector -->
    <div class="dp-sr-card">
        <h3><span class="dashicons dashicons-database"></span> Tabellen</h3>

        <div class="dp-sr-tables-header">
            <a href="#" id="dp-sr-select-all">Alles selecteren</a>
            <a href="#" id="dp-sr-select-none">Niets selecteren</a>
            <a href="#" id="dp-sr-select-core">Alleen WordPress-kern</a>
        </div>

        <div class="dp-sr-table-grid">
            <?php foreach ( $tables as $table ) :
                $is_core = in_array( $table, $core_tables, true );
            ?>
                <label class="dp-sr-table-item <?php echo $is_core ? 'is-core' : ''; ?>">
                    <input type="checkbox" name="dp_sr_tables[]" value="<?php echo esc_attr( $table ); ?>"
                           <?php checked( $is_core ); ?>>
                    <?php echo esc_html( str_replace( $wpdb->prefix, '', $table ) ); ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="dp-sr-actions">
        <button type="button" class="dp-sr-btn dp-sr-btn-secondary" id="dp-sr-dry-run">
            <span class="dashicons dashicons-visibility"></span> Dry Run
        </button>
        <button type="button" class="dp-sr-btn dp-sr-btn-primary" id="dp-sr-execute">
            <span class="dashicons dashicons-update"></span> Vervangen
        </button>
    </div>

    <div class="dp-sr-progress" id="dp-sr-progress">
        <div class="dp-sr-spinner"></div>
        <span class="dp-sr-progress-text" id="dp-sr-progress-text">Bezig...</span>
    </div>

    <!-- Results -->
    <div class="dp-sr-results" id="dp-sr-results"></div>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';

        // Table selection helpers
        document.getElementById('dp-sr-select-all').addEventListener('click', function(e) {
            e.preventDefault();
            toggleAll(true);
        });
        document.getElementById('dp-sr-select-none').addEventListener('click', function(e) {
            e.preventDefault();
            toggleAll(false);
        });
        document.getElementById('dp-sr-select-core').addEventListener('click', function(e) {
            e.preventDefault();
            var boxes = document.querySelectorAll('input[name="dp_sr_tables[]"]');
            var coreTables = <?php echo wp_json_encode( array_map( function( $t ) use ( $wpdb ) { return str_replace( $wpdb->prefix, '', $t ); }, $core_tables ) ); ?>;
            boxes.forEach(function(cb) {
                var name = cb.closest('.dp-sr-table-item').textContent.trim();
                cb.checked = coreTables.indexOf(name) !== -1;
            });
        });

        function toggleAll(state) {
            document.querySelectorAll('input[name="dp_sr_tables[]"]').forEach(function(cb) {
                cb.checked = state;
            });
        }

        // Dry Run
        document.getElementById('dp-sr-dry-run').addEventListener('click', function() {
            runReplace(true);
        });

        // Execute
        document.getElementById('dp-sr-execute').addEventListener('click', function() {
            var search = document.getElementById('dp-sr-search').value;
            if (!search) { alert('Voer een zoekterm in.'); return; }
            if (!confirm('Weet je zeker dat je "' + search + '" wilt vervangen in de database?\n\nDit kan niet ongedaan worden gemaakt.')) return;
            runReplace(false);
        });

        function runReplace(dryRun) {
            var search  = document.getElementById('dp-sr-search').value;
            var replace = document.getElementById('dp-sr-replace').value;
            var tables  = [];

            document.querySelectorAll('input[name="dp_sr_tables[]"]:checked').forEach(function(cb) {
                tables.push(cb.value);
            });

            if (!search) { alert('Voer een zoekterm in.'); return; }
            if (tables.length === 0) { alert('Selecteer minimaal één tabel.'); return; }

            var progress = document.getElementById('dp-sr-progress');
            var results  = document.getElementById('dp-sr-results');
            progress.classList.add('is-visible');
            document.getElementById('dp-sr-progress-text').textContent = dryRun ? 'Zoeken...' : 'Vervangen...';
            results.classList.remove('is-visible');

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_search_replace');
            fd.append('nonce', nonce);
            fd.append('search', search);
            fd.append('replace', replace);
            fd.append('dry_run', dryRun ? '1' : '');
            tables.forEach(function(t) { fd.append('tables[]', t); });

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    progress.classList.remove('is-visible');
                    results.classList.add('is-visible');

                    if (!res.success) {
                        results.innerHTML = '<div class="dp-sr-result-header is-empty">' + (res.data || 'Fout') + '</div>';
                        return;
                    }

                    var d = res.data;
                    var html = '';

                    if (d.total_changes === 0) {
                        html = '<div class="dp-sr-result-header is-empty">Geen resultaten gevonden voor deze zoekterm.</div>';
                    } else {
                        var cls = d.dry_run ? 'is-dry' : 'is-live';
                        var icon = d.dry_run ? '🔍' : '✅';
                        html = '<div class="dp-sr-result-header ' + cls + '">' + icon + ' ' + d.message + '</div>';
                        html += '<table class="dp-sr-result-table"><thead><tr><th>Tabel</th><th>Wijzigingen</th></tr></thead><tbody>';
                        d.tables.forEach(function(t) {
                            var name = t.table.replace('<?php echo esc_js( $wpdb->prefix ); ?>', '');
                            html += '<tr><td>' + name + '</td><td class="dp-sr-count">' + t.changes + '</td></tr>';
                        });
                        html += '</tbody></table>';

                        if (d.dry_run) {
                            html += '<p style="margin-top:12px;font-size:12px;color:#92400e;">Dit was een dry run — er is niets gewijzigd. Klik op <strong>Vervangen</strong> om de wijzigingen door te voeren.</p>';
                        }
                    }

                    results.innerHTML = html;
                })
                .catch(function() {
                    progress.classList.remove('is-visible');
                    results.classList.add('is-visible');
                    results.innerHTML = '<div class="dp-sr-result-header is-empty">Netwerkfout.</div>';
                });
        }
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
