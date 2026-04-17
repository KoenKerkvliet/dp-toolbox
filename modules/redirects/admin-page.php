<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Redirects',
        'Redirects',
        'manage_options',
        'dp-toolbox-redirects',
        'dp_toolbox_redirects_page'
    );
} );

function dp_toolbox_redirects_page() {
    $redirects = dp_toolbox_redirects_get_all();
    $nonce     = wp_create_nonce( 'dp_toolbox_redirects' );
    $ajax_url  = admin_url( 'admin-ajax.php' );
    $total     = count( $redirects );
    $active    = count( array_filter( $redirects, function ( $r ) { return ! empty( $r['active'] ); } ) );

    dp_toolbox_page_start( 'Redirects', 'Beheer 301/302 redirects — stuur oude URLs door naar nieuwe.' );
    ?>
    <style>
        .dp-rd-stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .dp-rd-stat {
            flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center;
        }
        .dp-rd-stat-num { display: block; font-size: 24px; font-weight: 700; color: #281E5D; }
        .dp-rd-stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.3px; }

        .dp-rd-toolbar {
            display: flex; gap: 10px; align-items: center; margin-bottom: 16px;
        }
        .dp-rd-btn {
            background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 8px 20px; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background 0.15s; display: inline-flex; align-items: center; gap: 6px;
        }
        .dp-rd-btn:hover { background: #4a3a8a; }
        .dp-rd-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }

        /* Table */
        .dp-rd-table { width: 100%; border-collapse: collapse; }
        .dp-rd-table th {
            text-align: left; font-size: 11px; font-weight: 600; color: #888;
            text-transform: uppercase; letter-spacing: 0.3px;
            padding: 8px 12px; border-bottom: 2px solid #281E5D;
        }
        .dp-rd-table td {
            padding: 10px 12px; border-bottom: 1px solid #eee;
            font-size: 13px; color: #1d2327; vertical-align: middle;
        }
        .dp-rd-table tr:hover td { background: #faf9ff; }
        .dp-rd-table tr.is-inactive td { opacity: 0.45; }
        .dp-rd-table tr.is-inactive:hover td { opacity: 1; }

        .dp-rd-from { font-family: monospace; font-size: 12px; color: #281E5D; word-break: break-all; }
        .dp-rd-to { font-size: 12px; color: #666; word-break: break-all; }
        .dp-rd-badge {
            display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px;
            border-radius: 4px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .dp-rd-badge.type-301 { background: #eee8ff; color: #281E5D; }
        .dp-rd-badge.type-302 { background: #fef3cd; color: #856404; }
        .dp-rd-badge.is-regex { background: #e0f2fe; color: #0369a1; font-size: 9px; margin-left: 4px; }

        .dp-rd-hits { font-size: 12px; color: #888; text-align: center; }
        .dp-rd-hits strong { color: #281E5D; }

        .dp-rd-actions { display: flex; gap: 6px; }
        .dp-rd-actions button {
            background: none; border: 1px solid #ddd; border-radius: 4px;
            padding: 4px 8px; cursor: pointer; font-size: 12px; color: #666;
            transition: all 0.15s;
        }
        .dp-rd-actions button:hover { border-color: #281E5D; color: #281E5D; }
        .dp-rd-actions button.dp-rd-delete:hover { border-color: #d63638; color: #d63638; }

        .dp-rd-empty {
            text-align: center; padding: 40px; color: #999; font-size: 14px;
        }
        .dp-rd-empty .dashicons { font-size: 40px; width: 40px; height: 40px; color: #ddd; display: block; margin: 0 auto 12px; }

        /* Modal / Form */
        .dp-rd-form-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 100000; justify-content: center; align-items: center;
        }
        .dp-rd-form-overlay.is-open { display: flex; }
        .dp-rd-form {
            background: #fff; border-radius: 10px; padding: 28px; width: 560px; max-width: 90vw;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        .dp-rd-form h3 {
            margin: 0 0 20px; font-size: 16px; font-weight: 700; color: #1d2327;
        }
        .dp-rd-form-row { margin-bottom: 14px; }
        .dp-rd-form-row label {
            display: block; font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px;
        }
        .dp-rd-form-row input[type="text"],
        .dp-rd-form-row select {
            width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; box-sizing: border-box;
        }
        .dp-rd-form-row input:focus, .dp-rd-form-row select:focus {
            border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
        }
        .dp-rd-form-inline { display: flex; gap: 12px; }
        .dp-rd-form-inline > * { flex: 1; }
        .dp-rd-form-check {
            display: flex; align-items: center; gap: 8px; margin-bottom: 14px;
        }
        .dp-rd-form-check label { margin: 0; text-transform: none; font-size: 13px; font-weight: 500; color: #1d2327; }
        .dp-rd-form-hint { font-size: 11px; color: #999; margin-top: 4px; }
        .dp-rd-form-actions { display: flex; gap: 10px; margin-top: 20px; }
        .dp-rd-form-cancel {
            background: #fff; color: #666; border: 1px solid #ddd; border-radius: 6px;
            padding: 8px 20px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .dp-rd-form-error {
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
            padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-top: 12px; display: none;
        }
    </style>

    <!-- Stats -->
    <div class="dp-rd-stats">
        <div class="dp-rd-stat">
            <span class="dp-rd-stat-num" id="dp-rd-total"><?php echo $total; ?></span>
            <span class="dp-rd-stat-label">Totaal</span>
        </div>
        <div class="dp-rd-stat">
            <span class="dp-rd-stat-num" id="dp-rd-active"><?php echo $active; ?></span>
            <span class="dp-rd-stat-label">Actief</span>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="dp-rd-toolbar">
        <button type="button" class="dp-rd-btn" id="dp-rd-add-btn">
            <span class="dashicons dashicons-plus-alt2"></span> Nieuwe redirect
        </button>
    </div>

    <!-- Table -->
    <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;">
        <table class="dp-rd-table">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>Van</th>
                    <th>Naar</th>
                    <th style="width:60px;">Type</th>
                    <th style="width:70px;text-align:center;">Hits</th>
                    <th style="width:110px;">Acties</th>
                </tr>
            </thead>
            <tbody id="dp-rd-tbody">
                <?php if ( empty( $redirects ) ) : ?>
                    <tr class="dp-rd-empty-row">
                        <td colspan="6">
                            <div class="dp-rd-empty">
                                <span class="dashicons dashicons-randomize"></span>
                                Nog geen redirects aangemaakt.
                            </div>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $redirects as $id => $rule ) : ?>
                        <tr data-id="<?php echo esc_attr( $id ); ?>" class="<?php echo empty( $rule['active'] ) ? 'is-inactive' : ''; ?>">
                            <td>
                                <div class="dp-toggle" style="transform:scale(0.8);">
                                    <input type="checkbox" id="dp-rd-active-<?php echo esc_attr( $id ); ?>"
                                           <?php checked( ! empty( $rule['active'] ) ); ?> class="dp-rd-toggle-active">
                                    <label for="dp-rd-active-<?php echo esc_attr( $id ); ?>"></label>
                                </div>
                            </td>
                            <td>
                                <span class="dp-rd-from"><?php echo esc_html( $rule['from'] ); ?></span>
                                <?php if ( ! empty( $rule['regex'] ) ) : ?>
                                    <span class="dp-rd-badge is-regex">regex</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="dp-rd-to"><?php echo esc_html( $rule['to'] ); ?></span></td>
                            <td><span class="dp-rd-badge type-<?php echo esc_attr( $rule['type'] ); ?>"><?php echo esc_html( $rule['type'] ); ?></span></td>
                            <td class="dp-rd-hits"><strong><?php echo (int) ( $rule['hits'] ?? 0 ); ?></strong></td>
                            <td>
                                <div class="dp-rd-actions">
                                    <button type="button" class="dp-rd-edit" title="Bewerken">
                                        <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span>
                                    </button>
                                    <button type="button" class="dp-rd-delete" title="Verwijderen">
                                        <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Modal -->
    <div class="dp-rd-form-overlay" id="dp-rd-modal">
        <div class="dp-rd-form">
            <h3 id="dp-rd-form-title">Nieuwe redirect</h3>
            <input type="hidden" id="dp-rd-form-id" value="">

            <div class="dp-rd-form-row">
                <label for="dp-rd-form-from">Van (pad)</label>
                <input type="text" id="dp-rd-form-from" placeholder="/oud-pad/">
                <p class="dp-rd-form-hint">Relatief pad vanaf de root, bijv. /oude-pagina/</p>
            </div>

            <div class="dp-rd-form-row">
                <label for="dp-rd-form-to">Naar (URL)</label>
                <input type="text" id="dp-rd-form-to" placeholder="https://voorbeeld.nl/nieuw-pad/">
                <p class="dp-rd-form-hint">Volledige URL of relatief pad</p>
            </div>

            <div class="dp-rd-form-inline">
                <div class="dp-rd-form-row">
                    <label for="dp-rd-form-type">Type</label>
                    <select id="dp-rd-form-type">
                        <option value="301">301 — Permanent</option>
                        <option value="302">302 — Tijdelijk</option>
                    </select>
                </div>
            </div>

            <div class="dp-rd-form-check">
                <input type="checkbox" id="dp-rd-form-regex">
                <label for="dp-rd-form-regex">Reguliere expressie (regex)</label>
            </div>

            <div class="dp-rd-form-check">
                <input type="checkbox" id="dp-rd-form-active" checked>
                <label for="dp-rd-form-active">Actief</label>
            </div>

            <div class="dp-rd-form-error" id="dp-rd-form-error"></div>

            <div class="dp-rd-form-actions">
                <button type="button" class="dp-rd-btn" id="dp-rd-form-save">Opslaan</button>
                <button type="button" class="dp-rd-form-cancel" id="dp-rd-form-cancel">Annuleren</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';
        var modal   = document.getElementById('dp-rd-modal');
        var redirects = <?php echo wp_json_encode( $redirects ); ?>;

        // Open modal
        document.getElementById('dp-rd-add-btn').addEventListener('click', function() {
            openModal();
        });

        // Cancel
        document.getElementById('dp-rd-form-cancel').addEventListener('click', closeModal);
        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });

        // Save
        document.getElementById('dp-rd-form-save').addEventListener('click', function() {
            var btn = this;
            var error = document.getElementById('dp-rd-form-error');
            error.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Opslaan...';

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_redirect_save');
            fd.append('nonce', nonce);
            fd.append('id', document.getElementById('dp-rd-form-id').value);
            fd.append('from', document.getElementById('dp-rd-form-from').value);
            fd.append('to', document.getElementById('dp-rd-form-to').value);
            fd.append('type', document.getElementById('dp-rd-form-type').value);
            fd.append('regex', document.getElementById('dp-rd-form-regex').checked ? '1' : '');
            fd.append('active', document.getElementById('dp-rd-form-active').checked ? '1' : '');

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        error.textContent = res.data || 'Fout bij opslaan.';
                        error.style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Opslaan';
                    }
                });
        });

        // Delegated events for table actions
        document.getElementById('dp-rd-tbody').addEventListener('click', function(e) {
            var btn = e.target.closest('button');
            if (!btn) return;
            var tr = btn.closest('tr');
            var id = tr ? tr.dataset.id : '';

            if (btn.classList.contains('dp-rd-edit') && id && redirects[id]) {
                openModal(id, redirects[id]);
            }

            if (btn.classList.contains('dp-rd-delete') && id) {
                if (!confirm('Deze redirect verwijderen?')) return;
                var fd = new FormData();
                fd.append('action', 'dp_toolbox_redirect_delete');
                fd.append('nonce', nonce);
                fd.append('id', id);
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) { if (res.success) location.reload(); });
            }
        });

        // Toggle active
        document.getElementById('dp-rd-tbody').addEventListener('change', function(e) {
            if (!e.target.classList.contains('dp-rd-toggle-active')) return;
            var tr = e.target.closest('tr');
            var id = tr ? tr.dataset.id : '';
            if (!id) return;

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_redirect_toggle');
            fd.append('nonce', nonce);
            fd.append('id', id);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        tr.classList.toggle('is-inactive', !res.data.active);
                    }
                });
        });

        function openModal(id, rule) {
            document.getElementById('dp-rd-form-title').textContent = id ? 'Redirect bewerken' : 'Nieuwe redirect';
            document.getElementById('dp-rd-form-id').value = id || '';
            document.getElementById('dp-rd-form-from').value = rule ? rule.from : '';
            document.getElementById('dp-rd-form-to').value = rule ? rule.to : '';
            document.getElementById('dp-rd-form-type').value = rule ? rule.type : '301';
            document.getElementById('dp-rd-form-regex').checked = rule ? !!rule.regex : false;
            document.getElementById('dp-rd-form-active').checked = rule ? !!rule.active : true;
            document.getElementById('dp-rd-form-error').style.display = 'none';
            document.getElementById('dp-rd-form-save').disabled = false;
            document.getElementById('dp-rd-form-save').textContent = 'Opslaan';
            modal.classList.add('is-open');
        }

        function closeModal() {
            modal.classList.remove('is-open');
        }
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
