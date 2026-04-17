<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Activity Log',
        'Activity Log',
        'manage_options',
        'dp-toolbox-activity-log',
        'dp_toolbox_activity_log_page'
    );
} );

function dp_toolbox_activity_log_page() {
    $nonce    = wp_create_nonce( 'dp_toolbox_activity_log' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    global $wpdb;
    $table = $wpdb->prefix . 'dp_activity_log';
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

    dp_toolbox_page_start( 'Activity Log', 'Overzicht van alle activiteiten op de site.' );
    ?>
    <style>
        .dp-al-toolbar {
            display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap;
        }
        .dp-al-search {
            flex: 1; min-width: 200px; padding: 7px 12px; border: 1px solid #ddd;
            border-radius: 6px; font-size: 13px;
        }
        .dp-al-search:focus { border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1); }
        .dp-al-filter {
            padding: 7px 12px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; background: #fff; cursor: pointer;
        }
        .dp-al-filter:focus { border-color: #281E5D; outline: none; }
        .dp-al-btn {
            background: #fff; border: 1px solid #ddd; border-radius: 6px;
            padding: 7px 14px; font-size: 12px; font-weight: 600; cursor: pointer;
            color: #666; transition: all 0.15s;
        }
        .dp-al-btn:hover { border-color: #281E5D; color: #281E5D; }
        .dp-al-btn-danger { color: #d63638; }
        .dp-al-btn-danger:hover { border-color: #d63638; color: #d63638; }

        .dp-al-stat { font-size: 12px; color: #888; }
        .dp-al-stat strong { color: #281E5D; font-weight: 700; }

        /* Table */
        .dp-al-wrap {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;
        }
        .dp-al-table { width: 100%; border-collapse: collapse; }
        .dp-al-table th {
            text-align: left; font-size: 11px; font-weight: 600; color: #888;
            text-transform: uppercase; letter-spacing: 0.3px;
            padding: 10px 14px; border-bottom: 1px solid #eee; background: #fafafa;
        }
        .dp-al-table td {
            padding: 10px 14px; border-bottom: 1px solid #f5f5f5;
            font-size: 13px; color: #1d2327; vertical-align: middle;
        }
        .dp-al-table tr:hover td { background: #faf9ff; }

        .dp-al-badge {
            display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px;
            border-radius: 4px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .dp-al-badge.type-auth { background: #dbeafe; color: #1e40af; }
        .dp-al-badge.type-content { background: #eee8ff; color: #281E5D; }
        .dp-al-badge.type-user { background: #fef3cd; color: #856404; }
        .dp-al-badge.type-plugin { background: #d1fae5; color: #065f46; }
        .dp-al-badge.type-settings { background: #fee2e2; color: #991b1b; }

        .dp-al-action { font-weight: 500; }
        .dp-al-object { font-size: 12px; color: #666; }
        .dp-al-details { font-size: 11px; color: #999; font-style: italic; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dp-al-user { font-size: 12px; color: #555; }
        .dp-al-user strong { color: #1d2327; }
        .dp-al-ip { font-size: 11px; color: #bbb; font-family: monospace; }
        .dp-al-date { font-size: 12px; color: #999; white-space: nowrap; }

        .dp-al-empty { text-align: center; padding: 40px; color: #999; }
        .dp-al-empty .dashicons { font-size: 40px; width: 40px; height: 40px; color: #ddd; display: block; margin: 0 auto 10px; }

        /* Pagination */
        .dp-al-pagination {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px; background: #fafafa; border-top: 1px solid #eee;
            font-size: 12px; color: #888;
        }
        .dp-al-page-btns { display: flex; gap: 4px; }
        .dp-al-page-btn {
            background: #fff; border: 1px solid #ddd; border-radius: 4px;
            padding: 4px 10px; font-size: 12px; cursor: pointer; color: #555;
        }
        .dp-al-page-btn:hover { border-color: #281E5D; color: #281E5D; }
        .dp-al-page-btn:disabled { opacity: 0.4; cursor: default; }

        /* Loading */
        .dp-al-loading { text-align: center; padding: 30px; color: #888; }
    </style>

    <div class="dp-al-toolbar">
        <input type="text" class="dp-al-search" id="dp-al-search" placeholder="Zoeken in activiteiten...">
        <select class="dp-al-filter" id="dp-al-filter">
            <option value="">Alle types</option>
            <option value="auth">Logins</option>
            <option value="content">Content</option>
            <option value="user">Gebruikers</option>
            <option value="plugin">Plugins & Thema's</option>
            <option value="settings">Instellingen</option>
        </select>
        <button type="button" class="dp-al-btn dp-al-btn-danger" id="dp-al-clear">Log wissen</button>
        <span class="dp-al-stat"><strong id="dp-al-total"><?php echo $total; ?></strong> activiteiten</span>
    </div>

    <div class="dp-al-wrap">
        <table class="dp-al-table">
            <thead>
                <tr>
                    <th style="width:80px;">Type</th>
                    <th>Actie</th>
                    <th>Gebruiker</th>
                    <th style="width:200px;">Details</th>
                    <th style="width:140px;">Datum</th>
                </tr>
            </thead>
            <tbody id="dp-al-tbody">
                <tr><td colspan="5" class="dp-al-loading">Laden...</td></tr>
            </tbody>
        </table>
        <div class="dp-al-pagination" id="dp-al-pagination" style="display:none;">
            <span id="dp-al-page-info"></span>
            <div class="dp-al-page-btns">
                <button type="button" class="dp-al-page-btn" id="dp-al-prev" disabled>&laquo; Vorige</button>
                <button type="button" class="dp-al-page-btn" id="dp-al-next">Volgende &raquo;</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var ajaxUrl  = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce    = '<?php echo esc_js( $nonce ); ?>';
        var tbody    = document.getElementById('dp-al-tbody');
        var page     = 1;
        var debounce = null;

        function loadLog() {
            var search = document.getElementById('dp-al-search').value;
            var type   = document.getElementById('dp-al-filter').value;

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_al_get_log');
            fd.append('nonce', nonce);
            fd.append('page', page);
            fd.append('search', search);
            fd.append('type', type);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) { tbody.innerHTML = '<tr><td colspan="5" class="dp-al-empty">Fout bij laden.</td></tr>'; return; }

                    var d = res.data;
                    document.getElementById('dp-al-total').textContent = d.total;

                    if (d.rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5"><div class="dp-al-empty"><span class="dashicons dashicons-shield"></span>Geen activiteiten gevonden.</div></td></tr>';
                        document.getElementById('dp-al-pagination').style.display = 'none';
                        return;
                    }

                    var html = '';
                    d.rows.forEach(function(row) {
                        html += '<tr>';
                        html += '<td><span class="dp-al-badge type-' + esc(row.event_type) + '">' + typeLabel(row.event_type) + '</span></td>';
                        html += '<td><span class="dp-al-action">' + esc(row.event_action) + '</span>';
                        if (row.object_name) html += '<br><span class="dp-al-object">' + esc(row.object_name) + '</span>';
                        html += '</td>';
                        html += '<td><span class="dp-al-user"><strong>' + esc(row.username || 'Systeem') + '</strong></span>';
                        if (row.user_ip) html += '<br><span class="dp-al-ip">' + esc(row.user_ip) + '</span>';
                        html += '</td>';
                        html += '<td><span class="dp-al-details" title="' + esc(row.details) + '">' + esc(row.details || '—') + '</span></td>';
                        html += '<td class="dp-al-date">' + esc(row.created_at) + '</td>';
                        html += '</tr>';
                    });
                    tbody.innerHTML = html;

                    // Pagination
                    var pag = document.getElementById('dp-al-pagination');
                    pag.style.display = 'flex';
                    document.getElementById('dp-al-page-info').textContent = 'Pagina ' + d.page + ' van ' + d.total_pages;
                    document.getElementById('dp-al-prev').disabled = d.page <= 1;
                    document.getElementById('dp-al-next').disabled = d.page >= d.total_pages;
                });
        }

        // Events
        document.getElementById('dp-al-search').addEventListener('input', function() {
            clearTimeout(debounce);
            debounce = setTimeout(function() { page = 1; loadLog(); }, 300);
        });
        document.getElementById('dp-al-filter').addEventListener('change', function() { page = 1; loadLog(); });
        document.getElementById('dp-al-prev').addEventListener('click', function() { if (page > 1) { page--; loadLog(); } });
        document.getElementById('dp-al-next').addEventListener('click', function() { page++; loadLog(); });
        document.getElementById('dp-al-clear').addEventListener('click', function() {
            if (!confirm('Weet je zeker dat je het volledige log wilt wissen? Dit kan niet ongedaan worden.')) return;
            var fd = new FormData();
            fd.append('action', 'dp_toolbox_al_clear');
            fd.append('nonce', nonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function() { page = 1; loadLog(); });
        });

        function typeLabel(type) {
            var labels = { auth: 'Login', content: 'Content', user: 'Gebruiker', plugin: 'Plugin', settings: 'Instelling' };
            return labels[type] || type;
        }

        function esc(str) { if (!str) return ''; var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

        // Init
        loadLog();
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
